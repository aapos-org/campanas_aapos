<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Webhook } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { useFormat } from '@/composables/useFormat';
import { index } from '@/routes/webhooks';
import type { Paginated } from '@/types';

type CallRow = {
    id: number;
    provider: string;
    method: string;
    status: 'received' | 'processed' | 'ignored' | 'failed';
    result: string | null;
    payload: unknown;
    headers: Record<string, unknown> | null;
    created_at: string | null;
};

defineProps<{
    calls: Paginated<CallRow>;
    webhookUrl: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Webhooks', href: index() }],
    },
});

const { dateTime } = useFormat();

const statusLabel: Record<CallRow['status'], string> = {
    received: 'Recibido',
    processed: 'Procesado',
    ignored: 'Ignorado',
    failed: 'Falló',
};

const statusVariant = (s: CallRow['status']) =>
    s === 'failed' ? 'destructive' : s === 'processed' ? 'default' : 'outline';
</script>

<template>
    <Head title="Webhooks" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Webhooks de ElasticEmail"
            description="Notificaciones de entregas, rebotes, quejas y bajas enviadas por el proveedor. Los rebotes duros y las quejas suprimen el correo en todas las audiencias."
        />

        <section class="rounded-xl border p-4 text-sm" aria-labelledby="cfg">
            <h2 id="cfg" class="mb-2 font-medium">Configuración</h2>
            <template v-if="webhookUrl">
                <p class="mb-2 text-muted-foreground">
                    En ElasticEmail (Settings → Webhooks) crea un webhook con
                    esta URL, método «POST JSON» y activa los eventos Sent,
                    Error, Unsubscribed y AbuseReport:
                </p>
                <code
                    class="block overflow-x-auto rounded-md bg-muted p-3 text-xs break-all"
                    >{{ webhookUrl }}</code
                >
                <p class="mt-2 text-muted-foreground">
                    Mantén esta URL en privado: contiene el token secreto.
                </p>
            </template>
            <p v-else class="text-muted-foreground">
                Falta el token. En el servidor ejecuta
                <code>php artisan app:webhook-token</code> y agrega la línea que
                muestra al archivo <code>.env</code>.
            </p>
        </section>

        <EmptyState
            v-if="calls.data.length === 0"
            :icon="Webhook"
            title="Aún no llegan notificaciones"
            description="Cuando ElasticEmail envíe eventos aparecerán aquí."
        />

        <ol v-else class="grid gap-3">
            <li
                v-for="call in calls.data"
                :key="call.id"
                class="rounded-xl border p-4"
            >
                <div
                    class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div class="min-w-0 space-y-1">
                        <p class="font-medium">
                            {{ call.result ?? 'Pendiente de procesar' }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ call.provider }} · {{ call.method }} · #{{
                                call.id
                            }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <Badge :variant="statusVariant(call.status)">{{
                            statusLabel[call.status]
                        }}</Badge>
                        <time
                            :datetime="call.created_at ?? undefined"
                            class="text-sm whitespace-nowrap text-muted-foreground"
                        >
                            {{ dateTime(call.created_at) }}
                        </time>
                    </div>
                </div>
                <details class="mt-2">
                    <summary
                        class="cursor-pointer text-sm text-muted-foreground"
                    >
                        Ver contenido recibido
                    </summary>
                    <pre
                        class="mt-2 overflow-x-auto rounded-md bg-muted p-3 text-xs"
                        >{{ JSON.stringify(call.payload, null, 2) }}</pre>
                </details>
            </li>
        </ol>

        <Pagination :page="calls" />
    </div>
</template>
