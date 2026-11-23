<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { Copy, Pause, Play, XCircle } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CampaignStatusBadge from '@/components/CampaignStatusBadge.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useFormat } from '@/composables/useFormat';
import {
    cancel,
    duplicate,
    index,
    pause,
    preview,
    resume,
    unschedule,
} from '@/routes/campaigns';
import type { CampaignDetail } from '@/types';

const props = defineProps<{
    campaign: CampaignDetail;
    stats: {
        opens: number;
        unique_opens: number;
        clicks: number;
        unique_clicks: number;
        pending: number;
    };
    canSend: boolean;
    canManage: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Campañas', href: index() }] },
});

const { dateTime, number } = useFormat();
const active = computed(() =>
    ['sending', 'paused', 'scheduled'].includes(props.campaign.status),
);
const { stop } = usePoll(
    3000,
    { only: ['campaign', 'stats'] },
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

        <p v-if="campaign.status === 'scheduled'" class="text-sm">
            Programada para el
            <strong>{{ dateTime(campaign.scheduled_at) }}</strong
            >.
        </p>

        <dl
            v-if="!campaign.is_historic"
            class="grid grid-cols-2 gap-3 sm:grid-cols-4"
        >
            <div class="rounded-xl border p-3">
                <dt class="text-xs text-muted-foreground">Aperturas únicas</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(stats.unique_opens) }}
                    <span class="text-sm font-normal text-muted-foreground">{{
                        rate(stats.unique_opens)
                    }}</span>
                </dd>
            </div>
            <div class="rounded-xl border p-3">
                <dt class="text-xs text-muted-foreground">Aperturas totales</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(stats.opens) }}
                </dd>
            </div>
            <div class="rounded-xl border p-3">
                <dt class="text-xs text-muted-foreground">Clics únicos</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(stats.unique_clicks) }}
                    <span class="text-sm font-normal text-muted-foreground">{{
                        rate(stats.unique_clicks)
                    }}</span>
                </dd>
            </div>
            <div class="rounded-xl border p-3">
                <dt class="text-xs text-muted-foreground">Clics totales</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(stats.clicks) }}
                </dd>
            </div>
        </dl>
        <dl v-else-if="historic" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl border p-3">
                <dt class="text-xs text-muted-foreground">Enviados</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(historic.emails_sent ?? 0) }}
                </dd>
            </div>
            <div class="rounded-xl border p-3">
                <dt class="text-xs text-muted-foreground">Aperturas únicas</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(historic.opens?.unique_opens ?? 0) }}
                </dd>
            </div>
            <div class="rounded-xl border p-3">
                <dt class="text-xs text-muted-foreground">Clics únicos</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(historic.clicks?.unique_subscriber_clicks ?? 0) }}
                </dd>
            </div>
            <div class="rounded-xl border p-3">
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
