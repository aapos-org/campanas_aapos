<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    AlertCircle,
    AlertTriangle,
    Check,
    ChevronRight,
    Copy,
    Info,
    Loader2,
    RefreshCw,
    Sparkles,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import type { ReviewStatus } from '@/composables/useContentReview';
import { useFormat } from '@/composables/useFormat';
import { edit as aiSettings } from '@/routes/admin/ai';
import type { AssistantConfig, ReviewFinding, ReviewMetrics } from '@/types';

/**
 * Advisory review panel next to the editor: Smart Brevity metrics first, then findings
 * grouped by severity, each with the affected fragment and a suggestion to copy.
 */
const props = defineProps<{
    config: AssistantConfig;
    status: ReviewStatus;
    reason: string | null;
    pausedReason: string | null;
    localFindings: ReviewFinding[];
    aiFindings: ReviewFinding[];
    metrics: ReviewMetrics | null;
    cached: boolean;
    liveAllowed: boolean;
    live: boolean;
    liveOn: boolean;
    smartBrevity: boolean;
}>();

const emit = defineEmits<{
    'update:liveOn': [value: boolean];
    'update:smartBrevity': [value: boolean];
    reviewNow: [];
}>();

const open = ref(true);
const { number } = useFormat();

const findings = computed(() =>
    [...props.localFindings, ...props.aiFindings].filter(
        (f) => props.smartBrevity || f.category !== 'smart_brevity',
    ),
);
const groups = computed(() =>
    (
        [
            {
                key: 'error',
                title: 'Conviene corregir',
                icon: AlertCircle,
                tone: 'text-destructive',
            },
            {
                key: 'warning',
                title: 'Recomendaciones',
                icon: AlertTriangle,
                tone: 'text-amber-600 dark:text-amber-400',
            },
            {
                key: 'info',
                title: 'Sugerencias',
                icon: Info,
                tone: 'text-primary',
            },
        ] as const
    )
        .map((g) => ({
            ...g,
            items: findings.value.filter((f) => f.severity === g.key),
        }))
        .filter((g) => g.items.length),
);

const categoryLabel: Record<string, string> = {
    asunto: 'Asunto',
    variables: 'Variables',
    enlaces: 'Enlaces',
    imagenes: 'Imágenes',
    spam: 'Spam',
    ortografia: 'Ortografía',
    tono: 'Tono',
    claridad: 'Claridad',
    coherencia: 'Coherencia',
    smart_brevity: 'Smart Brevity',
};

type Meter = { label: string; value: string; ok: boolean };
const meters = computed<Meter[]>(() => {
    const m = props.metrics;

    if (!m) {
        return [];
    }

    return [
        {
            label: 'Palabras',
            value: `${number(m.words)} / ${number(m.max_words)}`,
            ok: m.words <= m.max_words,
        },
        {
            label: 'Asunto',
            value: `${m.subject_chars} / ${m.subject_max_chars} caracteres`,
            ok: m.subject_chars > 0 && m.subject_chars <= m.subject_max_chars,
        },
        {
            label: 'Media por frase',
            value: `${m.avg_sentence_words} palabras`,
            ok: m.avg_sentence_words <= m.sentence_max_words,
        },
        {
            label: 'Párrafos largos',
            value: String(m.long_paragraphs),
            ok: m.long_paragraphs === 0,
        },
        {
            label: 'Viñetas',
            value: m.has_bullets ? 'Sí' : 'No',
            ok: m.has_bullets,
        },
        { label: 'Negritas', value: m.has_bold ? 'Sí' : 'No', ok: m.has_bold },
    ];
});

const statusText = computed(() => {
    if (props.pausedReason) {
        return props.pausedReason;
    }

    switch (props.status) {
        case 'reviewing':
            return 'Revisando con IA…';
        case 'ready':
            return props.cached
                ? 'Revisado (sin cambios desde la última revisión).'
                : 'Revisado con IA.';
        case 'unavailable':
        case 'error':
            return props.reason ?? 'La revisión con IA no está disponible.';
        default:
            return props.live
                ? 'Se revisará al hacer una pausa al escribir.'
                : 'Chequeos automáticos activos.';
    }
});

const copy = async (text: string) => {
    try {
        await navigator.clipboard.writeText(text);
        toast.success('Sugerencia copiada.');
    } catch {
        toast.error('No se pudo copiar.');
    }
};
</script>

<template>
    <aside
        class="flex min-h-0 flex-col overflow-hidden glass-card"
        aria-label="Revisión del contenido"
    >
        <button
            type="button"
            class="flex items-center justify-between gap-2 border-b p-3 text-left"
            :aria-expanded="open"
            @click="open = !open"
        >
            <span class="flex items-center gap-2 text-sm font-medium">
                <Sparkles class="size-4 text-primary" aria-hidden="true" />
                Revisión del contenido
                <span
                    v-if="findings.length"
                    class="rounded-full bg-primary/15 px-2 text-xs text-primary tabular-nums"
                    >{{ findings.length }}</span
                >
            </span>
            <ChevronRight
                class="size-4 transition-transform"
                :class="open ? 'rotate-90' : ''"
                aria-hidden="true"
            />
        </button>

        <div
            v-if="open"
            class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-3"
        >
            <!-- Controls -->
            <div class="space-y-2 text-sm">
                <label v-if="liveAllowed" class="flex items-center gap-2"
                    ><Checkbox
                        :model-value="liveOn"
                        @update:model-value="
                            (v) => emit('update:liveOn', v === true)
                        "
                    />Revisión en vivo</label
                >
                <label
                    v-if="config.smart_brevity_enabled"
                    class="flex items-center gap-2"
                    ><Checkbox
                        :model-value="smartBrevity"
                        @update:model-value="
                            (v) => emit('update:smartBrevity', v === true)
                        "
                    />Aplicar Smart Brevity</label
                >
                <Button
                    v-if="config.ai_available && config.manual_review_enabled"
                    type="button"
                    size="sm"
                    variant="secondary"
                    class="w-full"
                    :disabled="status === 'reviewing'"
                    @click="emit('reviewNow')"
                    ><Loader2
                        v-if="status === 'reviewing'"
                        class="animate-spin"
                        aria-hidden="true"
                    /><RefreshCw v-else aria-hidden="true" />Revisar
                    ahora</Button
                >
                <p class="text-xs text-muted-foreground" aria-live="polite">
                    {{ statusText }}
                </p>
                <p
                    v-if="!config.ai_available"
                    class="text-xs text-muted-foreground"
                >
                    <template v-if="config.can_configure"
                        >La revisión con IA está desactivada.
                        <Link
                            :href="aiSettings()"
                            class="underline underline-offset-2"
                            >Configurar el asistente de IA</Link
                        ></template
                    >
                    <template v-else
                        >Se muestran los chequeos automáticos. La revisión con
                        IA no está activa en esta organización.</template
                    >
                </p>
            </div>

            <!-- Smart Brevity metrics -->
            <section
                v-if="smartBrevity && meters.length"
                aria-labelledby="rv-metrics"
            >
                <h3
                    id="rv-metrics"
                    class="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    Smart Brevity
                </h3>
                <dl class="grid grid-cols-2 gap-2">
                    <div
                        v-for="m in meters"
                        :key="m.label"
                        class="rounded-lg bg-muted px-2.5 py-2"
                    >
                        <dt class="text-[11px] text-muted-foreground">
                            {{ m.label }}
                        </dt>
                        <dd
                            class="flex items-center gap-1 text-sm font-medium tabular-nums"
                        >
                            <Check
                                v-if="m.ok"
                                class="size-3.5 text-emerald-600 dark:text-emerald-400"
                                aria-label="Bien"
                            />
                            <AlertTriangle
                                v-else
                                class="size-3.5 text-amber-600 dark:text-amber-400"
                                aria-label="Revisar"
                            />
                            {{ m.value }}
                        </dd>
                    </div>
                </dl>
            </section>

            <!-- Findings -->
            <div aria-live="polite" class="space-y-4">
                <p
                    v-if="!findings.length"
                    class="rounded-lg bg-muted px-3 py-2 text-sm text-muted-foreground"
                >
                    Sin observaciones por ahora.
                </p>
                <section v-for="g in groups" :key="g.key" :aria-label="g.title">
                    <h3
                        class="mb-2 flex items-center gap-1.5 text-xs font-semibold tracking-wide uppercase"
                        :class="g.tone"
                    >
                        <component
                            :is="g.icon"
                            class="size-3.5"
                            aria-hidden="true"
                        />
                        {{ g.title }} ({{ g.items.length }})
                    </h3>
                    <ul class="space-y-2">
                        <li
                            v-for="(f, i) in g.items"
                            :key="`${g.key}-${i}`"
                            class="space-y-1.5 rounded-lg border border-border bg-background/40 p-2.5 text-sm"
                        >
                            <p
                                class="flex items-center gap-1.5 text-[11px] text-muted-foreground"
                            >
                                <span class="rounded bg-muted px-1.5 py-0.5">{{
                                    categoryLabel[f.category] ?? f.category
                                }}</span>
                                <span
                                    v-if="f.source === 'ai'"
                                    class="flex items-center gap-0.5"
                                    ><Sparkles
                                        class="size-3"
                                        aria-hidden="true"
                                    />IA</span
                                >
                            </p>
                            <p>{{ f.message }}</p>
                            <blockquote
                                v-if="f.excerpt"
                                class="border-l-2 border-border pl-2 text-xs text-muted-foreground italic"
                            >
                                «{{ f.excerpt }}»
                            </blockquote>
                            <div
                                v-if="f.suggestion"
                                class="rounded-md bg-primary/10 p-2 text-xs"
                            >
                                <p class="mb-1 font-medium text-primary">
                                    Sugerencia
                                </p>
                                <p class="whitespace-pre-line">
                                    {{ f.suggestion }}
                                </p>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    class="mt-1 h-7 px-2 text-xs"
                                    @click="copy(f.suggestion)"
                                    ><Copy aria-hidden="true" />Copiar</Button
                                >
                            </div>
                        </li>
                    </ul>
                </section>
            </div>
            <p class="text-[11px] text-muted-foreground">
                Solo son recomendaciones: no impiden guardar ni enviar.
            </p>
        </div>
    </aside>
</template>
