<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { Copy, Download, Pause, Play, XCircle } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, reactive, ref, watch } from 'vue';
import CampaignStatusBadge from '@/components/CampaignStatusBadge.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Pagination from '@/components/Pagination.vue';
import BarChart from '@/components/reports/BarChart.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFormat } from '@/composables/useFormat';
import {
    cancel,
    abWinner,
    duplicate,
    exportMethod as exportReport,
    index,
    pause,
    preview,
    resume,
    show,
    unschedule,
} from '@/routes/campaigns';
import { show as contactShow } from '@/routes/contacts';
import type { CampaignDetail, Paginated } from '@/types';

type RecipientRow = {
    id: number;
    contact_id: number | null;
    email: string;
    name: string;
    status: string;
    status_label: string;
    sent_at: string | null;
    opens: number;
    clicks: number;
    bounce: 'duro' | 'suave' | null;
    unsubscribed: boolean;
    complained: boolean;
    last_activity_at: string | null;
    error: string | null;
};

const props = defineProps<{
    campaign: CampaignDetail;
    stats: {
        sent: number;
        delivered: number;
        opens: number;
        unique_opens: number;
        clicks: number;
        unique_clicks: number;
        hard_bounces: number;
        soft_bounces: number;
        complaints: number;
        unsubscribes: number;
        failed: number;
        pending: number;
    };
    links: { url: string; clicks: number; unique_clicks: number }[];
    timeline: {
        unit: 'hour' | 'day';
        points: { at: string; opens: number; clicks: number }[];
    } | null;
    recipients: Paginated<RecipientRow> | null;
    filters: { activity: string; q: string };
    activityOptions: Record<string, string>;
    abResults:
        | {
              id: number;
              label: string;
              sent: number;
              opens: number;
              clicks: number;
              open_rate: number | null;
              click_rate: number | null;
          }[]
        | null;
    canSend: boolean;
    canManage: boolean;
    canReport: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Campañas', href: index() }] },
});

const { dateTime, number } = useFormat();
const active = computed(() =>
    ['sending', 'paused', 'scheduled', 'testing'].includes(
        props.campaign.status,
    ),
);
const { stop } = usePoll(
    3000,
    { only: ['campaign', 'stats', 'timeline', 'abResults'] },
    { autoStart: props.campaign.status === 'sending' },
);
watch(
    () => props.campaign.status,
    (s) => s !== 'sending' && stop(),
);

const progress = computed(() =>
    props.campaign.recipients_count
        ? Math.round(
              ((props.campaign.sent_count +
                  props.campaign.failed_count +
                  props.campaign.skipped_count) *
                  100) /
                  props.campaign.recipients_count,
          )
        : 0,
);
const rate = (n: number) =>
    props.campaign.sent_count
        ? `${Math.round((n * 1000) / props.campaign.sent_count) / 10} %`
        : '—';
const confirmingCancel = ref(false);

const filter = reactive({ ...props.filters });
watchDebounced(
    filter,
    () =>
        router.get(
            show.url(props.campaign.id),
            {
                activity: filter.activity || undefined,
                q: filter.q || undefined,
            },
            {
                only: ['recipients', 'filters'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        ),
    { debounce: 300 },
);
const exportUrl = computed(() =>
    exportReport.url(props.campaign.id, {
        query: { activity: filter.activity || undefined },
    }),
);
const series = (key: 'opens' | 'clicks') =>
    (props.timeline?.points ?? []).map((p) => ({
        label: p.at,
        value: p[key],
    }));
const selectClass =
    'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:bg-input/30';
const historic = computed(
    () => props.campaign.report_summary as Record<string, any> | null,
);
</script>

<template>
    <Head :title="campaign.name" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="min-w-0">
                <h1 class="truncate text-2xl font-semibold tracking-tight">
                    {{ campaign.name }}
                </h1>
                <p class="text-muted-foreground">
                    {{ campaign.subject }} ·
                    {{ campaign.audience ?? 'Sin audiencia' }}
                </p>
            </div>
            <CampaignStatusBadge
                :status="campaign.status"
                :label="campaign.status_label"
            />
        </div>

        <Alert
            v-if="campaign.status_reason"
            :variant="campaign.status === 'failed' ? 'destructive' : 'default'"
        >
            <AlertTitle>{{
                campaign.status === 'paused' ? 'Envío en pausa' : 'Aviso'
            }}</AlertTitle>
            <AlertDescription>{{ campaign.status_reason }}</AlertDescription>
        </Alert>

        <section
            v-if="!campaign.is_historic && campaign.recipients_count"
            class="space-y-2"
            aria-label="Progreso del envío"
        >
            <div class="flex justify-between text-sm text-muted-foreground">
                <span
                    >{{ number(campaign.sent_count) }} enviados de
                    {{ number(campaign.recipients_count)
                    }}<template v-if="campaign.failed_count">
                        · {{ number(campaign.failed_count) }} con
                        error</template
                    ><template v-if="campaign.skipped_count">
                        ·
                        {{ number(campaign.skipped_count) }} omitidos</template
                    ></span
                >
                <span class="tabular-nums">{{ progress }} %</span>
            </div>
            <div
                class="h-3 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                :aria-valuenow="progress"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-label="Progreso del envío"
            >
                <div
                    class="h-full rounded-full bg-primary transition-all"
                    :style="{ width: `${progress}%` }"
                />
            </div>
        </section>

        <section
            v-if="abResults && abResults.length"
            class="space-y-3 glass-card p-4"
            aria-labelledby="s-ab"
        >
            <div
                class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
            >
                <h2 id="s-ab" class="font-medium">
                    Prueba A/B ·
                    {{
                        campaign.ab_type === 'subject'
                            ? 'asunto'
                            : campaign.ab_type === 'from_name'
                              ? 'remitente'
                              : 'contenido'
                    }}
                </h2>
                <Button
                    v-if="canSend && campaign.status === 'testing'"
                    size="sm"
                    @click="router.post(abWinner.url(campaign.id))"
                    >Elegir ganador ahora</Button
                >
            </div>
            <p class="text-sm text-muted-foreground">
                <template v-if="campaign.status === 'testing'"
                    >Muestra enviada. El ganador (más
                    {{
                        campaign.ab_metric === 'opens' ? 'aperturas' : 'clics'
                    }}) se elegirá automáticamente
                    {{ campaign.ab_wait_hours }} horas después de terminar la
                    muestra.</template
                >
                <template v-else-if="campaign.ab_decided_at"
                    >Ganador elegido el
                    {{ dateTime(campaign.ab_decided_at) }} por
                    {{
                        campaign.ab_metric === 'opens' ? 'aperturas' : 'clics'
                    }}.</template
                >
                <template v-else
                    >Se envía primero al {{ campaign.ab_sample_percent }} % de
                    los destinatarios.</template
                >
            </p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-muted-foreground">
                        <tr>
                            <th class="py-1 font-medium">Versión</th>
                            <th class="py-1 font-medium">Detalle</th>
                            <th class="py-1 text-right font-medium">
                                Enviados
                            </th>
                            <th class="py-1 text-right font-medium">
                                Aperturas
                            </th>
                            <th class="py-1 text-right font-medium">Clics</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in abResults" :key="r.id" class="border-t">
                            <td class="py-2">
                                {{ r.label }}
                                <Badge
                                    v-if="
                                        campaign.ab_winner_variant_id === r.id
                                    "
                                    class="ml-1"
                                    >Ganadora</Badge
                                >
                            </td>
                            <td class="max-w-[16rem] truncate py-2">
                                {{
                                    campaign.ab_type === 'subject'
                                        ? (campaign.variants.find(
                                              (v) => v.id === r.id,
                                          )?.subject ?? campaign.subject)
                                        : campaign.ab_type === 'from_name'
                                          ? (campaign.variants.find(
                                                (v) => v.id === r.id,
                                            )?.from_name ?? campaign.from_name)
                                          : `Contenido ${r.label}`
                                }}
                            </td>
                            <td class="py-2 text-right tabular-nums">
                                {{ number(r.sent) }}
                            </td>
                            <td class="py-2 text-right tabular-nums">
                                {{ number(r.opens) }}
                                <span class="text-muted-foreground"
                                    >({{ r.open_rate ?? '—' }} %)</span
                                >
                            </td>
                            <td class="py-2 text-right tabular-nums">
                                {{ number(r.clicks) }}
                                <span class="text-muted-foreground"
                                    >({{ r.click_rate ?? '—' }} %)</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <p v-if="campaign.status === 'scheduled'" class="text-sm">
            Programada para el
            <strong>{{ dateTime(campaign.scheduled_at) }}</strong
            >.
        </p>

        <dl
            v-if="!campaign.is_historic"
            class="grid grid-cols-2 gap-3 sm:grid-cols-4"
        >
            <div class="glass-card p-3">
                <dt class="text-xs text-muted-foreground">Aperturas únicas</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(stats.unique_opens) }}
                    <span class="text-sm font-normal text-muted-foreground">{{
                        rate(stats.unique_opens)
                    }}</span>
                </dd>
            </div>
            <div class="glass-card p-3">
                <dt class="text-xs text-muted-foreground">Aperturas totales</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(stats.opens) }}
                </dd>
            </div>
            <div class="glass-card p-3">
                <dt class="text-xs text-muted-foreground">Clics únicos</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(stats.unique_clicks) }}
                    <span class="text-sm font-normal text-muted-foreground">{{
                        rate(stats.unique_clicks)
                    }}</span>
                </dd>
            </div>
            <div class="glass-card p-3">
                <dt class="text-xs text-muted-foreground">Clics totales</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(stats.clicks) }}
                </dd>
            </div>
        </dl>
        <dl
            v-if="!campaign.is_historic && stats.sent"
            class="grid grid-cols-2 gap-3 sm:grid-cols-5"
        >
            <div class="glass-card p-3">
                <dt class="text-xs text-muted-foreground">
                    Entregados (proveedor)
                </dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(stats.delivered) }}
                </dd>
            </div>
            <div class="glass-card p-3">
                <dt class="text-xs text-muted-foreground">Rebotes duros</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(stats.hard_bounces) }}
                </dd>
            </div>
            <div class="glass-card p-3">
                <dt class="text-xs text-muted-foreground">Rebotes suaves</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(stats.soft_bounces) }}
                </dd>
            </div>
            <div class="glass-card p-3">
                <dt class="text-xs text-muted-foreground">Quejas de spam</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(stats.complaints) }}
                </dd>
            </div>
            <div class="glass-card p-3">
                <dt class="text-xs text-muted-foreground">Bajas</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(stats.unsubscribes) }}
                    <span class="text-sm font-normal text-muted-foreground">{{
                        rate(stats.unsubscribes)
                    }}</span>
                </dd>
            </div>
        </dl>
        <dl v-else-if="historic" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="glass-card p-3">
                <dt class="text-xs text-muted-foreground">Enviados</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(historic.emails_sent ?? 0) }}
                </dd>
            </div>
            <div class="glass-card p-3">
                <dt class="text-xs text-muted-foreground">Aperturas únicas</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(historic.opens?.unique_opens ?? 0) }}
                </dd>
            </div>
            <div class="glass-card p-3">
                <dt class="text-xs text-muted-foreground">Clics únicos</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(historic.clicks?.unique_subscriber_clicks ?? 0) }}
                </dd>
            </div>
            <div class="glass-card p-3">
                <dt class="text-xs text-muted-foreground">Bajas</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(historic.unsubscribed ?? 0) }}
                </dd>
            </div>
        </dl>
        <p class="text-xs text-muted-foreground">
            Las aperturas son aproximadas: algunos clientes de correo (como
            Apple Mail) cargan las imágenes automáticamente. Los clics son una
            medida más fiable.
        </p>

        <section
            v-if="canReport && timeline?.points.length"
            class="grid gap-6 glass-card p-4 md:grid-cols-2"
            aria-label="Actividad en el tiempo"
        >
            <BarChart
                :title="`Aperturas por ${timeline.unit === 'hour' ? 'hora' : 'día'}`"
                :points="series('opens')"
                value-label="aperturas"
            />
            <BarChart
                :title="`Clics por ${timeline.unit === 'hour' ? 'hora' : 'día'}`"
                :points="series('clicks')"
                value-label="clics"
            />
        </section>

        <section
            v-if="canReport && links.length"
            class="space-y-2"
            aria-labelledby="s-links"
        >
            <h2 id="s-links" class="font-medium">Enlaces más visitados</h2>
            <div class="overflow-x-auto glass-card">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Enlace</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Clics únicos
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Clics totales
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in links" :key="l.url" class="border-t">
                            <td class="max-w-[28rem] truncate px-3 py-2">
                                <a
                                    :href="l.url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="underline-offset-2 hover:underline"
                                    >{{ l.url }}</a
                                >
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ number(l.unique_clicks) }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ number(l.clicks) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section
            v-if="canReport && recipients"
            class="space-y-3"
            aria-labelledby="s-recipients"
        >
            <div
                class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
            >
                <h2 id="s-recipients" class="font-medium">Destinatarios</h2>
                <div class="flex flex-wrap gap-2">
                    <label class="sr-only" for="r-activity">Actividad</label>
                    <select
                        id="r-activity"
                        v-model="filter.activity"
                        :class="selectClass"
                    >
                        <option value="">Todos</option>
                        <option
                            v-for="(label, key) in activityOptions"
                            :key="key"
                            :value="key"
                        >
                            {{ label }}
                        </option>
                    </select>
                    <label class="sr-only" for="r-q">Buscar correo</label>
                    <Input
                        id="r-q"
                        v-model="filter.q"
                        type="search"
                        placeholder="Buscar correo"
                        class="w-48"
                    />
                    <Button variant="secondary" as-child
                        ><a :href="exportUrl"
                            ><Download aria-hidden="true" />Exportar CSV</a
                        ></Button
                    >
                </div>
            </div>
            <div class="overflow-x-auto glass-card">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Correo</th>
                            <th class="px-3 py-2 font-medium">Envío</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Aperturas
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Clics
                            </th>
                            <th class="px-3 py-2 font-medium">Eventos</th>
                            <th class="px-3 py-2 font-medium">
                                Última actividad
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!recipients.data.length">
                            <td
                                colspan="6"
                                class="px-3 py-6 text-center text-muted-foreground"
                            >
                                No hay destinatarios con ese filtro.
                            </td>
                        </tr>
                        <tr
                            v-for="r in recipients.data"
                            :key="r.id"
                            class="border-t"
                        >
                            <td class="px-3 py-2">
                                <Link
                                    v-if="r.contact_id"
                                    :href="contactShow(r.contact_id)"
                                    class="underline-offset-2 hover:underline"
                                    >{{ r.email }}</Link
                                >
                                <span v-else>{{ r.email }}</span>
                                <span
                                    v-if="r.name"
                                    class="block text-xs text-muted-foreground"
                                    >{{ r.name }}</span
                                >
                            </td>
                            <td class="px-3 py-2" :title="r.error ?? undefined">
                                {{ r.status_label }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ number(r.opens) }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ number(r.clicks) }}
                            </td>
                            <td class="space-x-1 px-3 py-2">
                                <Badge v-if="r.bounce" variant="destructive"
                                    >Rebote {{ r.bounce }}</Badge
                                >
                                <Badge v-if="r.complained" variant="destructive"
                                    >Spam</Badge
                                >
                                <Badge v-if="r.unsubscribed" variant="outline"
                                    >Baja</Badge
                                >
                            </td>
                            <td
                                class="px-3 py-2 whitespace-nowrap text-muted-foreground"
                            >
                                {{ dateTime(r.last_activity_at) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :page="recipients" />
        </section>

        <div class="flex flex-wrap gap-2">
            <template v-if="canSend && active">
                <Button
                    v-if="campaign.status === 'sending'"
                    variant="secondary"
                    @click="router.post(pause.url(campaign.id))"
                    ><Pause aria-hidden="true" />Pausar</Button
                >
                <Button
                    v-if="campaign.status === 'paused'"
                    @click="router.post(resume.url(campaign.id))"
                    ><Play aria-hidden="true" />Reanudar</Button
                >
                <Button
                    v-if="campaign.status === 'scheduled'"
                    variant="secondary"
                    @click="router.post(unschedule.url(campaign.id))"
                    >Quitar programación</Button
                >
                <Button
                    variant="ghost"
                    class="text-destructive"
                    @click="confirmingCancel = true"
                    ><XCircle aria-hidden="true" />Cancelar envío</Button
                >
            </template>
            <Button
                v-if="canManage"
                variant="ghost"
                @click="router.post(duplicate.url(campaign.id))"
                ><Copy aria-hidden="true" />Duplicar como borrador</Button
            >
        </div>

        <section class="space-y-2" aria-labelledby="s-content">
            <h2 id="s-content" class="font-medium">Contenido enviado</h2>
            <iframe
                :src="preview.url(campaign.id)"
                sandbox=""
                title="Contenido de la campaña"
                class="h-[600px] w-full rounded-lg border bg-white"
            />
        </section>

        <p class="text-xs text-muted-foreground">
            <template v-if="campaign.started_at"
                >Inicio: {{ dateTime(campaign.started_at) }}</template
            >
            <template v-if="campaign.finished_at">
                · Fin: {{ dateTime(campaign.finished_at) }}</template
            >
            <template v-if="campaign.connection">
                · Conexión: {{ campaign.connection }}</template
            >
        </p>
        <Link :href="index()" class="text-sm text-muted-foreground underline"
            >Volver a campañas</Link
        >
    </div>

    <ConfirmDialog
        v-model:open="confirmingCancel"
        title="¿Cancelar el envío?"
        description="Quienes ya recibieron la campaña no se ven afectados; el resto no la recibirá. No se puede deshacer."
        confirm-label="Cancelar envío"
        destructive
        @confirm="
            router.post(
                cancel.url(campaign.id),
                {},
                { onFinish: () => (confirmingCancel = false) },
            )
        "
    />
</template>
