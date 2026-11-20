<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import {
    index as audiencesIndex,
    show as showAudience,
} from '@/routes/audiences';
import { start } from '@/routes/imports';
import type { ImportSummary, Option } from '@/types';

const props = defineProps<{
    import: ImportSummary;
    headers: string[];
    preview: string[][];
    targets: Option[];
    suggested: string[];
    tags: string[];
    statuses: Option[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Audiencias', href: audiencesIndex() }] },
});

const { number } = useFormat();

const mapping = reactive<Record<number, string>>(
    Object.fromEntries(
        props.headers.map((_, i) => [i, props.suggested[i] ?? 'ignore']),
    ),
);
const form = reactive({
    status: 'non_subscribed',
    update_existing: true,
    tags: '',
    consent_confirmed: false,
    consent_detail: '',
});
const errors = ref<Record<string, string>>({});
const processing = ref(false);

const hasEmail = computed(
    () => Object.values(mapping).filter((t) => t === 'email').length === 1,
);

const submit = () => {
    router.post(
        start.url(props.import.id),
        {
            mapping,
            status: form.status,
            update_existing: form.update_existing,
            tags: form.tags
                .split(',')
                .map((t) => t.trim())
                .filter(Boolean),
            consent_confirmed: form.consent_confirmed,
            consent_detail: form.consent_detail,
        },
        {
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onError: (e) => (errors.value = e),
        },
    );
};
</script>

<template>
    <Head title="Mapear columnas" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Mapear columnas"
            :description="`Paso 2 de 2 · ${props.import.file} · ${number(props.import.total_rows)} filas → «${props.import.audience?.name}»`"
        />

        <section aria-labelledby="mapping-title" class="space-y-3">
            <h2 id="mapping-title" class="font-medium">
                ¿Qué contiene cada columna?
            </h2>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="(header, i) in headers"
                    :key="i"
                    class="space-y-2 rounded-xl border p-3"
                >
                    <Label :for="`col-${i}`" class="font-medium">{{
                        header || `Columna ${i + 1}`
                    }}</Label>
                    <NativeSelect :id="`col-${i}`" v-model="mapping[i]">
                        <option
                            v-for="t in targets"
                            :key="t.value"
                            :value="t.value"
                        >
                            {{ t.label }}
                        </option>
                    </NativeSelect>
                    <p class="truncate text-xs text-muted-foreground">
                        Ej.:
                        {{
                            preview
                                .map((r) => r[i])
                                .filter(Boolean)
                                .slice(0, 3)
                                .join(' · ') || '(vacío)'
                        }}
                    </p>
                </div>
            </div>
            <InputError :message="errors.mapping" />
            <p
                v-if="!hasEmail"
                class="text-sm text-amber-700 dark:text-amber-400"
            >
                Elige exactamente una columna como «Correo electrónico».
            </p>
        </section>

        <section aria-labelledby="options-title" class="max-w-2xl space-y-4">
            <h2 id="options-title" class="font-medium">Opciones</h2>

            <div class="grid gap-1.5">
                <Label for="status">Los contactos nuevos quedarán como</Label>
                <NativeSelect id="status" v-model="form.status">
                    <option
                        v-for="s in statuses"
                        :key="s.value"
                        :value="s.value"
                    >
                        {{ s.label }}
                    </option>
                </NativeSelect>
                <InputError :message="errors.status" />
            </div>

            <Alert v-if="form.status === 'subscribed'">
                <AlertTriangle />
                <AlertTitle>Solo con consentimiento</AlertTitle>
                <AlertDescription class="space-y-3">
                    <p>
                        Importa como suscritas únicamente a personas que
                        aceptaron recibir tus correos. Quedará registrado como
                        prueba de consentimiento.
                    </p>
                    <div class="grid gap-1.5">
                        <Label for="consent_detail"
                            >¿Cómo se obtuvo el consentimiento?</Label
                        >
                        <Input
                            id="consent_detail"
                            v-model="form.consent_detail"
                            placeholder="Ej.: Formulario de inscripción del congreso 2026"
                        />
                        <InputError :message="errors.consent_detail" />
                    </div>
                    <label class="flex items-start gap-2">
                        <Checkbox
                            :model-value="form.consent_confirmed"
                            @update:model-value="
                                (v) => (form.consent_confirmed = v === true)
                            "
                        />
                        <span
                            >Confirmo que estas personas dieron su
                            consentimiento.</span
                        >
                    </label>
                    <InputError :message="errors.consent_confirmed" />
                </AlertDescription>
            </Alert>

            <label class="flex items-start gap-2 text-sm">
                <Checkbox
                    :model-value="form.update_existing"
                    @update:model-value="
                        (v) => (form.update_existing = v === true)
                    "
                />
                <span>
                    Actualizar los datos de contactos que ya existen (solo con
                    valores no vacíos; nunca cambia bajas ni rebotes).
                </span>
            </label>

            <div class="grid gap-1.5">
                <Label for="tags"
                    >Etiquetas para todos (opcional, separadas por comas)</Label
                >
                <Input id="tags" v-model="form.tags" list="tag-list" />
                <datalist id="tag-list">
                    <option v-for="t in tags" :key="t" :value="t" />
                </datalist>
            </div>
        </section>

        <div class="flex flex-col-reverse gap-3 sm:flex-row">
            <Button variant="outline" as-child>
                <Link :href="showAudience(props.import.audience?.id ?? 0)"
                    >Cancelar</Link
                >
            </Button>
            <Button :disabled="processing || !hasEmail" @click="submit">
                <Spinner v-if="processing" />
                Importar {{ number(props.import.total_rows) }} filas
            </Button>
        </div>
    </div>
</template>
