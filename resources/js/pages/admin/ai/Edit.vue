<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    KeyRound,
    Loader2,
    ShieldAlert,
    Sparkles,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { edit, update, verify } from '@/routes/admin/ai';
import {
    destroy as destroyKey,
    store as storeKey,
} from '@/routes/admin/ai/key';

type Settings = {
    provider: string;
    model: string;
    has_key: boolean;
    masked_key: string | null;
    enabled: boolean;
    live_review_enabled: boolean;
    manual_review_enabled: boolean;
    debounce_ms: number;
    min_chars_change: number;
    categories: string[];
    max_findings: number;
    smart_brevity_enabled: boolean;
    smart_brevity_max_words: number;
    tone_guidelines: string | null;
    daily_limit: number | null;
    monthly_limit: number | null;
    timeout_seconds: number;
    status: 'unverified' | 'active' | 'error';
    last_verified_at: string | null;
    last_error: string | null;
};

const props = defineProps<{
    settings: Settings;
    categoryOptions: { value: string; label: string }[];
    suggestedModels: { id: string; label: string }[];
    usage: {
        day: number;
        month: number;
        daily_limit: number | null;
        monthly_limit: number | null;
    };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Asistente de IA', href: edit() }] },
});

const { dateTime, number } = useFormat();

const form = useForm({
    enabled: props.settings.enabled,
    model: props.settings.model,
    live_review_enabled: props.settings.live_review_enabled,
    manual_review_enabled: props.settings.manual_review_enabled,
    debounce_ms: props.settings.debounce_ms,
    min_chars_change: props.settings.min_chars_change,
    categories: [...props.settings.categories],
    max_findings: props.settings.max_findings,
    smart_brevity_enabled: props.settings.smart_brevity_enabled,
    smart_brevity_max_words: props.settings.smart_brevity_max_words,
    tone_guidelines: props.settings.tone_guidelines ?? '',
    daily_limit:
        props.settings.daily_limit === null
            ? ''
            : String(props.settings.daily_limit),
    monthly_limit:
        props.settings.monthly_limit === null
            ? ''
            : String(props.settings.monthly_limit),
    timeout_seconds: props.settings.timeout_seconds,
});

const keyForm = useForm({ api_key: '' });
const replacing = ref(!props.settings.has_key);
const confirmingDelete = ref(false);
const verifying = ref(false);

const toggleCategory = (value: string) => {
    form.categories = form.categories.includes(value)
        ? form.categories.filter((c) => c !== value)
        : [...form.categories, value];
};

const save = () =>
    form
        .transform((d) => ({
            ...d,
            tone_guidelines: d.tone_guidelines || null,
            daily_limit: d.daily_limit === '' ? null : Number(d.daily_limit),
            monthly_limit:
                d.monthly_limit === '' ? null : Number(d.monthly_limit),
        }))
        .put(update.url(), { preserveScroll: true });

const saveKey = () =>
    keyForm.put(storeKey.url(), {
        preserveScroll: true,
        onSuccess: () => {
            keyForm.reset();
            replacing.value = false;
        },
    });

const runVerify = () =>
    router.post(
        verify.url(),
        {},
        {
            preserveScroll: true,
            onStart: () => (verifying.value = true),
            onFinish: () => (verifying.value = false),
        },
    );

const monthPct = computed(() =>
    props.usage.monthly_limit
        ? Math.min(
              100,
              Math.round((props.usage.month * 100) / props.usage.monthly_limit),
          )
        : null,
);

const statusLabel = {
    unverified: 'Sin verificar',
    active: 'Conectado',
    error: 'Con error',
};
</script>

<template>
    <Head title="Asistente de IA" />

    <div class="flex max-w-3xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Asistente de IA"
            description="Revisa el texto de las campañas mientras se escriben: ortografía, tono, claridad, frases tipo spam y el método Smart Brevity. Solo aconseja: nunca bloquea guardar ni enviar."
        />

        <section
            class="space-y-2 glass-card p-4 text-sm"
            aria-labelledby="s-privacy"
        >
            <h2 id="s-privacy" class="flex items-center gap-2 font-medium">
                <ShieldAlert class="size-4 text-primary" aria-hidden="true" />
                Qué se envía y quién lo cobra
            </h2>
            <p class="text-muted-foreground">
                Al revisar se envían al proveedor (Anthropic) solo el asunto, el
                texto de vista previa y el texto del correo. Nunca se envían
                contactos, correos ni datos de la audiencia. El uso lo cobra el
                proveedor en la cuenta de la API key; los límites de abajo
                ayudan a controlar el costo.
            </p>
        </section>

        <!-- API key -->
        <section class="space-y-4 glass-card p-5" aria-labelledby="s-key">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="s-key" class="flex items-center gap-2 font-medium">
                    <KeyRound class="size-4 text-primary" aria-hidden="true" />
                    API key de Anthropic
                </h2>
                <Badge
                    :variant="
                        settings.status === 'active'
                            ? 'default'
                            : settings.status === 'error'
                              ? 'destructive'
                              : 'outline'
                    "
                    >{{ statusLabel[settings.status] }}</Badge
                >
            </div>

            <div
                v-if="settings.has_key && !replacing"
                class="flex flex-wrap items-center gap-3"
            >
                <code
                    class="rounded-lg bg-muted px-3 py-1.5 text-sm tracking-wider"
                    >{{ settings.masked_key }}</code
                >
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    @click="replacing = true"
                    >Reemplazar</Button
                >
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="text-destructive"
                    @click="confirmingDelete = true"
                    ><Trash2 aria-hidden="true" />Eliminar</Button
                >
            </div>

            <form v-else class="grid gap-2" @submit.prevent="saveKey">
                <Label for="api_key">{{
                    settings.has_key ? 'Nueva API key' : 'API key'
                }}</Label>
                <PasswordInput
                    id="api_key"
                    v-model="keyForm.api_key"
                    autocomplete="off"
                    placeholder="sk-ant-…"
                />
                <p class="text-xs text-muted-foreground">
                    Créala en console.anthropic.com → API keys. Se guarda
                    cifrada y solo se muestran sus últimos 4 caracteres. Al
                    guardarla se verifica la conexión.
                </p>
                <InputError :message="keyForm.errors.api_key" />
                <div class="flex gap-2">
                    <Button
                        type="submit"
                        :disabled="keyForm.processing || !keyForm.api_key"
                        ><Spinner v-if="keyForm.processing" />Guardar y
                        verificar</Button
                    >
                    <Button
                        v-if="settings.has_key"
                        type="button"
                        variant="ghost"
                        @click="
                            replacing = false;
                            keyForm.reset();
                        "
                        >Cancelar</Button
                    >
                </div>
            </form>

            <div class="flex flex-wrap items-center gap-3 text-sm">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="!settings.has_key || verifying"
                    @click="runVerify"
                    ><Loader2
                        v-if="verifying"
                        class="animate-spin"
                        aria-hidden="true"
                    /><CheckCircle2 v-else aria-hidden="true" />Verificar
                    conexión</Button
                >
                <span
                    v-if="settings.last_verified_at"
                    class="text-muted-foreground"
                    >Última verificación:
                    {{ dateTime(settings.last_verified_at) }}</span
                >
            </div>
            <p
                v-if="settings.status === 'error' && settings.last_error"
                class="text-sm text-destructive"
                role="alert"
            >
                {{ settings.last_error }}
            </p>
        </section>

        <form class="space-y-6 glass-card p-5" @submit.prevent="save">
            <section class="space-y-3" aria-labelledby="s-general">
                <h2 id="s-general" class="flex items-center gap-2 font-medium">
                    <Sparkles class="size-4 text-primary" aria-hidden="true" />
                    Funcionamiento
                </h2>
                <label class="flex items-center gap-2 text-sm"
                    ><Checkbox
                        :model-value="form.enabled"
                        @update:model-value="(v) => (form.enabled = v === true)"
                    />Activar el asistente de IA</label
                >
                <label class="flex items-center gap-2 text-sm"
                    ><Checkbox
                        :model-value="form.live_review_enabled"
                        @update:model-value="
                            (v) => (form.live_review_enabled = v === true)
                        "
                    />Revisión en vivo mientras se escribe</label
                >
                <label class="flex items-center gap-2 text-sm"
                    ><Checkbox
                        :model-value="form.manual_review_enabled"
                        @update:model-value="
                            (v) => (form.manual_review_enabled = v === true)
                        "
                    />Botón «Revisar ahora»</label
                >
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="model">Modelo</Label>
                        <Input
                            id="model"
                            v-model="form.model"
                            list="ai-models"
                            required
                        />
                        <datalist id="ai-models">
                            <option
                                v-for="m in suggestedModels"
                                :key="m.id"
                                :value="m.id"
                            >
                                {{ m.label }}
                            </option>
                        </datalist>
                        <p class="text-xs text-muted-foreground">
                            Sugeridos:
                            <template
                                v-for="(m, i) in suggestedModels"
                                :key="m.id"
                                ><button
                                    type="button"
                                    class="underline underline-offset-2"
                                    @click="form.model = m.id"
                                >
                                    {{ m.label }}</button
                                ><template
                                    v-if="i < suggestedModels.length - 1"
                                >
                                    ·
                                </template></template
                            >
                        </p>
                        <InputError :message="form.errors.model" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="debounce"
                            >Pausa antes de revisar (ms)</Label
                        >
                        <Input
                            id="debounce"
                            v-model.number="form.debounce_ms"
                            type="number"
                            min="800"
                            max="5000"
                            step="100"
                        />
                        <p class="text-xs text-muted-foreground">
                            Tiempo sin escribir antes de lanzar la revisión en
                            vivo (800–5000).
                        </p>
                        <InputError :message="form.errors.debounce_ms" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="minchars"
                            >Cambio mínimo para revisar (caracteres)</Label
                        >
                        <Input
                            id="minchars"
                            v-model.number="form.min_chars_change"
                            type="number"
                            min="1"
                            max="500"
                        />
                        <InputError :message="form.errors.min_chars_change" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="timeout">Tiempo de espera (segundos)</Label>
                        <Input
                            id="timeout"
                            v-model.number="form.timeout_seconds"
                            type="number"
                            min="5"
                            max="120"
                        />
                        <InputError :message="form.errors.timeout_seconds" />
                    </div>
                </div>
            </section>

            <section class="space-y-3" aria-labelledby="s-cats">
                <h2 id="s-cats" class="font-medium">Qué revisa la IA</h2>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label
                        v-for="c in categoryOptions.filter(
                            (o) => o.value !== 'smart_brevity',
                        )"
                        :key="c.value"
                        class="flex items-center gap-2 text-sm"
                        ><Checkbox
                            :model-value="form.categories.includes(c.value)"
                            @update:model-value="toggleCategory(c.value)"
                        />{{ c.label }}</label
                    >
                </div>
                <div class="grid gap-1.5 sm:max-w-xs">
                    <Label for="maxf">Máximo de hallazgos por revisión</Label>
                    <Input
                        id="maxf"
                        v-model.number="form.max_findings"
                        type="number"
                        min="1"
                        max="20"
                    />
                    <InputError :message="form.errors.max_findings" />
                </div>
            </section>

            <section class="space-y-3" aria-labelledby="s-sb">
                <h2 id="s-sb" class="font-medium">
                    Smart Brevity (método de Axios)
                </h2>
                <label class="flex items-center gap-2 text-sm"
                    ><Checkbox
                        :model-value="form.smart_brevity_enabled"
                        @update:model-value="
                            (v) => (form.smart_brevity_enabled = v === true)
                        "
                    />Evaluar Smart Brevity y proponer reescrituras</label
                >
                <p class="text-xs text-muted-foreground">
                    Asunto corto con la palabra más fuerte primero, una primera
                    frase con lo más importante, «Por qué importa», frases y
                    párrafos cortos, negritas y viñetas para escanear, una sola
                    llamada a la acción y extensión contenida.
                </p>
                <div class="grid gap-1.5 sm:max-w-xs">
                    <Label for="sbwords">Máximo de palabras del correo</Label>
                    <Input
                        id="sbwords"
                        v-model.number="form.smart_brevity_max_words"
                        type="number"
                        min="50"
                        max="2000"
                    />
                    <InputError
                        :message="form.errors.smart_brevity_max_words"
                    />
                </div>
            </section>

            <section class="space-y-2" aria-labelledby="s-tone">
                <h2 id="s-tone" class="font-medium">Guía de tono y estilo</h2>
                <textarea
                    id="tone"
                    v-model="form.tone_guidelines"
                    rows="5"
                    maxlength="2000"
                    aria-labelledby="s-tone"
                    placeholder="Ej.: Cercano y agradecido, de «usted», sin tecnicismos. Hablamos de las obras del Santo Hermano Pedro con respeto…"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:bg-input/30"
                />
                <p class="text-xs text-muted-foreground">
                    {{ form.tone_guidelines.length }}/2000 · Se usa solo como
                    contexto de estilo para revisar el tono.
                </p>
                <InputError :message="form.errors.tone_guidelines" />
            </section>

            <section class="space-y-3" aria-labelledby="s-limits">
                <h2 id="s-limits" class="font-medium">Límites y uso</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="daily">Revisiones con IA por día</Label>
                        <Input
                            id="daily"
                            v-model="form.daily_limit"
                            type="number"
                            min="1"
                            placeholder="Sin límite"
                        />
                        <InputError :message="form.errors.daily_limit" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="monthly">Revisiones con IA por mes</Label>
                        <Input
                            id="monthly"
                            v-model="form.monthly_limit"
                            type="number"
                            min="1"
                            placeholder="Sin límite"
                        />
                        <InputError :message="form.errors.monthly_limit" />
                    </div>
                </div>
                <p class="text-sm">
                    Este mes:
                    <strong class="tabular-nums">{{
                        number(usage.month)
                    }}</strong>
                    revisiones<template v-if="usage.monthly_limit">
                        de {{ number(usage.monthly_limit) }} ({{
                            monthPct
                        }}
                        %)</template
                    >
                    · hoy:
                    <strong class="tabular-nums">{{ number(usage.day) }}</strong
                    >. Las revisiones repetidas sobre el mismo texto salen de la
                    caché y no cuentan.
                </p>
            </section>

            <Button type="submit" :disabled="form.processing"
                ><Spinner v-if="form.processing" />Guardar configuración</Button
            >
        </form>
    </div>

    <ConfirmDialog
        v-model:open="confirmingDelete"
        title="¿Eliminar la API key?"
        description="El asistente dejará de revisar con IA hasta que guardes otra key. Los chequeos locales siguen funcionando."
        confirm-label="Eliminar"
        destructive
        @confirm="
            router.delete(destroyKey.url(), {
                preserveScroll: true,
                onFinish: () => (confirmingDelete = false),
            })
        "
    />
</template>
