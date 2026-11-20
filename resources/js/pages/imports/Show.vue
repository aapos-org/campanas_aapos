<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { Download, XCircle } from '@lucide/vue';
import { watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useFormat } from '@/composables/useFormat';
import { show as showAudience } from '@/routes/audiences';
import { cancel, index, report } from '@/routes/imports';
import type { ImportSummary } from '@/types';

const props = defineProps<{
    import: ImportSummary;
    issues: {
        row_number: number;
        email: string | null;
        outcome: string;
        reason: string;
    }[];
    issuesTotal: number;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Importaciones', href: index() }] },
});

const { number, dateTime } = useFormat();

// Poll every 2 s while the import runs (no WebSockets).
const { stop } = usePoll(
    2000,
    { only: ['import', 'issues', 'issuesTotal'] },
    { autoStart: !props.import.finished },
);
watch(
    () => props.import.finished,
    (finished) => finished && stop(),
);

const outcomeLabel: Record<string, string> = {
    failed: 'Error',
    skipped: 'Omitida',
    warning: 'Advertencia',
};

const stats = [
    { key: 'created_count', label: 'Nuevos' },
    { key: 'updated_count', label: 'Actualizados' },
    { key: 'skipped_count', label: 'Omitidos' },
    { key: 'failed_count', label: 'Con error' },
] as const;
</script>

<template>
    <Head title="Importación" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
        >
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Importación #{{ props.import.id }}
                </h1>
                <p class="text-muted-foreground">
                    {{ props.import.file }} →
                    <Link
                        v-if="props.import.audience"
                        :href="showAudience(props.import.audience.id)"
                        class="underline-offset-4 hover:underline"
                        >{{ props.import.audience.name }}</Link
                    >
                </p>
            </div>
            <Badge variant="outline">{{ props.import.status_label }}</Badge>
        </div>

        <section aria-label="Progreso" class="space-y-2">
            <div class="flex justify-between text-sm text-muted-foreground">
                <span
                    >{{ number(props.import.processed_rows) }} de
                    {{ number(props.import.total_rows) }} filas</span
                >
                <span class="tabular-nums">{{ props.import.progress }}%</span>
            </div>
            <div
                class="h-3 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                :aria-valuenow="props.import.progress"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-label="Progreso de la importación"
            >
                <div
                    class="h-full rounded-full bg-primary transition-all"
                    :style="{ width: `${props.import.progress}%` }"
                />
            </div>
            <p
                v-if="props.import.error"
                class="text-sm text-destructive"
                role="alert"
            >
                {{ props.import.error }}
            </p>
        </section>

        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div v-for="s in stats" :key="s.key" class="rounded-xl border p-3">
                <dt class="text-xs text-muted-foreground">{{ s.label }}</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(props.import[s.key]) }}
                </dd>
            </div>
        </dl>

        <div class="flex flex-wrap gap-2">
            <Button
                v-if="!props.import.finished"
                variant="secondary"
                @click="router.post(cancel.url(props.import.id))"
            >
                <XCircle aria-hidden="true" />
                Cancelar importación
            </Button>
            <Button
                v-if="issuesTotal > 0"
                as="a"
                :href="report.url(props.import.id)"
                variant="secondary"
            >
                <Download aria-hidden="true" />
                Descargar reporte ({{ number(issuesTotal) }} filas)
            </Button>
        </div>

        <section
            v-if="issues.length"
            aria-labelledby="issues-title"
            class="space-y-3"
        >
            <h2 id="issues-title" class="font-medium">
                Filas con observaciones
                <span
                    v-if="issuesTotal > issues.length"
                    class="text-sm font-normal text-muted-foreground"
                >
                    (primeras {{ issues.length }}; descarga el reporte para
                    verlas todas)
                </span>
            </h2>
            <ul class="grid gap-2">
                <li
                    v-for="issue in issues"
                    :key="`${issue.row_number}-${issue.reason}`"
                    class="flex flex-col gap-1 rounded-lg border p-3 text-sm sm:flex-row sm:items-center sm:gap-3"
                >
                    <span class="w-20 shrink-0 text-muted-foreground"
                        >Fila {{ issue.row_number }}</span
                    >
                    <Badge
                        :variant="
                            issue.outcome === 'failed'
                                ? 'destructive'
                                : 'outline'
                        "
                        class="shrink-0"
                        >{{
                            outcomeLabel[issue.outcome] ?? issue.outcome
                        }}</Badge
                    >
                    <span class="min-w-0 truncate font-medium">{{
                        issue.email ?? '—'
                    }}</span>
                    <span class="text-muted-foreground">{{
                        issue.reason
                    }}</span>
                </li>
            </ul>
        </section>

        <p class="text-xs text-muted-foreground">
            Creada {{ dateTime(props.import.created_at) }}
            <template v-if="props.import.finished_at">
                · Terminó {{ dateTime(props.import.finished_at) }}</template
            >
        </p>
    </div>
</template>
