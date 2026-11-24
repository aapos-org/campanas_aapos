<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { postJson } from '@/lib/http';
import { index, preview, store, update } from '@/routes/segments';

type FieldDef = {
    key: string;
    label: string;
    group: string;
    ops: string[];
    input: string;
    options?: { value: string; label: string }[];
};
type Condition = { field: string; op: string; value: string | number | null };
type Preview = {
    total: number;
    subscribed: number;
    sample: { id: number; email: string; name: string; status: string }[];
};

const props = defineProps<{
    segment: {
        id: number;
        name: string;
        audience_id: number;
        match: 'all' | 'any';
        conditions: Condition[];
    } | null;
    defaultAudience: number | null;
    audiences: { id: number; name: string }[];
    catalog: FieldDef[];
    operators: Record<string, string>;
    tags: { id: number; name: string }[];
    campaigns: {
        id: number;
        name: string;
        historic: boolean;
        date: string | null;
    }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Segmentos', href: index() }] },
});

const { number } = useFormat();
const fields = computed(() => new Map(props.catalog.map((f) => [f.key, f])));
const groups = computed(() => [...new Set(props.catalog.map((f) => f.group))]);
const NO_VALUE = ['blank', 'not_blank'];
const DAYS = [
    'within_days',
    'not_within_days',
    'opened_within_days',
    'not_opened_within_days',
    'clicked_within_days',
    'not_clicked_within_days',
];

const form = useForm({
    name: props.segment?.name ?? '',
    audience_id:
        props.segment?.audience_id ??
        props.defaultAudience ??
        props.audiences[0]?.id ??
        null,
    match: props.segment?.match ?? 'all',
    conditions: (props.segment?.conditions ?? [
        { field: 'tag', op: 'has', value: null },
    ]) as Condition[],
});

const def = (c: Condition) => fields.value.get(c.field);
const inputFor = (c: Condition) =>
    DAYS.includes(c.op) ? 'number' : (def(c)?.input ?? 'text');

const changeField = (c: Condition) => {
    c.op = def(c)?.ops[0] ?? 'is';
    c.value = null;
};
const add = () =>
    form.conditions.push({ field: 'tag', op: 'has', value: null });
const remove = (i: number) => form.conditions.splice(i, 1);

const months = [
    'Enero',
    'Febrero',
    'Marzo',
    'Abril',
    'Mayo',
    'Junio',
    'Julio',
    'Agosto',
    'Septiembre',
    'Octubre',
    'Noviembre',
    'Diciembre',
];

const result = ref<Preview | null>(null);
const previewError = ref<string | null>(null);
const loading = ref(false);

const complete = computed(() =>
    form.conditions.every(
        (c) => NO_VALUE.includes(c.op) || (c.value !== null && c.value !== ''),
    ),
);

watchDebounced(
    () => [form.audience_id, form.match, JSON.stringify(form.conditions)],
    async () => {
        if (!form.audience_id || !complete.value || !form.conditions.length) {
            result.value = null;

            return;
        }

        loading.value = true;
        previewError.value = null;

        try {
            result.value = await postJson<Preview>(preview.url(), {
                audience_id: form.audience_id,
                match: form.match,
                conditions: form.conditions,
            });
        } catch (e) {
            result.value = null;
            previewError.value = (e as Error).message;
        } finally {
            loading.value = false;
        }
    },
    { debounce: 400, immediate: true },
);

const submit = () =>
    props.segment
        ? form.put(update.url(props.segment.id))
        : form.post(store.url());

const cancel = () => router.visit(index.url());
</script>

<template>
    <Head :title="segment ? `Segmento · ${segment.name}` : 'Nuevo segmento'" />

    <form
        class="flex flex-1 flex-col gap-6 p-4 md:p-6"
        @submit.prevent="submit"
    >
        <Heading
            :title="segment ? 'Editar segmento' : 'Nuevo segmento'"
            description="Un segmento es un grupo dinámico: se recalcula cada vez que se usa, por eso siempre está al día."
        />

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-1.5">
                <Label for="name">Nombre</Label>
                <Input id="name" v-model="form.name" required maxlength="150" />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-1.5">
                <Label for="audience">Audiencia</Label>
                <NativeSelect id="audience" v-model="form.audience_id">
                    <option v-for="a in audiences" :key="a.id" :value="a.id">
                        {{ a.name }}
                    </option>
                </NativeSelect>
                <InputError :message="form.errors.audience_id" />
            </div>
        </div>

        <fieldset class="space-y-3 rounded-xl border p-4">
            <legend class="px-1 text-sm font-medium">Condiciones</legend>
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span>Incluir contactos que cumplan</span>
                <label class="sr-only" for="match">Tipo de coincidencia</label>
                <select
                    id="match"
                    v-model="form.match"
                    class="h-9 rounded-md border border-input bg-transparent px-2 text-sm dark:bg-input/30"
                >
                    <option value="all">todas las condiciones (Y)</option>
                    <option value="any">al menos una condición (O)</option>
                </select>
            </div>

            <ol class="space-y-2">
                <li
                    v-for="(c, i) in form.conditions"
                    :key="i"
                    class="grid gap-2 rounded-lg bg-muted/40 p-3 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-center"
                >
                    <label class="sr-only" :for="`f-${i}`"
                        >Campo de la condición {{ i + 1 }}</label
                    >
                    <NativeSelect
                        :id="`f-${i}`"
                        v-model="c.field"
                        @change="changeField(c)"
                    >
                        <optgroup v-for="g in groups" :key="g" :label="g">
                            <option
                                v-for="f in catalog.filter(
                                    (x) => x.group === g,
                                )"
                                :key="f.key"
                                :value="f.key"
                            >
                                {{ f.label }}
                            </option>
                        </optgroup>
                    </NativeSelect>

                    <label class="sr-only" :for="`o-${i}`"
                        >Comparación de la condición {{ i + 1 }}</label
                    >
                    <NativeSelect :id="`o-${i}`" v-model="c.op">
                        <option
                            v-for="op in def(c)?.ops ?? []"
                            :key="op"
                            :value="op"
                        >
                            {{ operators[op] }}
                        </option>
                    </NativeSelect>

                    <div v-if="!NO_VALUE.includes(c.op)">
                        <label class="sr-only" :for="`v-${i}`"
                            >Valor de la condición {{ i + 1 }}</label
                        >
                        <NativeSelect
                            v-if="inputFor(c) === 'tag'"
                            :id="`v-${i}`"
                            v-model="c.value"
                        >
                            <option :value="null" disabled>Elige…</option>
                            <option v-for="t in tags" :key="t.id" :value="t.id">
                                {{ t.name }}
                            </option>
                        </NativeSelect>
                        <NativeSelect
                            v-else-if="inputFor(c) === 'campaign'"
                            :id="`v-${i}`"
                            v-model="c.value"
                        >
                            <option :value="null" disabled>Elige…</option>
                            <option
                                v-for="cp in campaigns"
                                :key="cp.id"
                                :value="cp.id"
                            >
                                {{ cp.name }}{{ cp.date ? ` (${cp.date})` : ''
                                }}{{ cp.historic ? ' · Mailchimp' : '' }}
                            </option>
                        </NativeSelect>
                        <NativeSelect
                            v-else-if="inputFor(c) === 'select'"
                            :id="`v-${i}`"
                            v-model="c.value"
                        >
                            <option :value="null" disabled>Elige…</option>
                            <option
                                v-for="o in def(c)?.options ?? []"
                                :key="o.value"
                                :value="o.value"
                            >
                                {{ o.label }}
                            </option>
                        </NativeSelect>
                        <NativeSelect
                            v-else-if="inputFor(c) === 'month'"
                            :id="`v-${i}`"
                            v-model="c.value"
                        >
                            <option :value="null" disabled>Elige…</option>
                            <option
                                v-for="(m, mi) in months"
                                :key="m"
                                :value="mi + 1"
                            >
                                {{ m }}
                            </option>
                        </NativeSelect>
                        <Input
                            v-else
                            :id="`v-${i}`"
                            v-model="c.value as string"
                            :type="
                                inputFor(c) === 'date'
                                    ? 'date'
                                    : inputFor(c) === 'number'
                                      ? 'number'
                                      : 'text'
                            "
                            :min="inputFor(c) === 'number' ? 0 : undefined"
                        />
                    </div>
                    <div v-else class="hidden sm:block" />

                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :disabled="form.conditions.length === 1"
                        :aria-label="`Quitar la condición ${i + 1}`"
                        @click="remove(i)"
                        ><Trash2 aria-hidden="true"
                    /></Button>
                </li>
            </ol>
            <InputError :message="form.errors.conditions" />
            <Button
                type="button"
                variant="secondary"
                size="sm"
                :disabled="form.conditions.length >= 20"
                @click="add"
                ><Plus aria-hidden="true" />Agregar condición</Button
            >
            <p
                v-if="
                    form.conditions.some(
                        (c) =>
                            c.field === 'campaign' &&
                            campaigns.find((x) => x.id === c.value)?.historic,
                    )
                "
                class="text-xs text-muted-foreground"
            >
                Las campañas de Mailchimp no guardan la actividad por persona:
                las condiciones de apertura o clic sobre ellas no encontrarán
                coincidencias.
            </p>
        </fieldset>

        <section
            class="rounded-xl border p-4"
            aria-live="polite"
            aria-labelledby="s-preview"
        >
            <h2 id="s-preview" class="mb-2 flex items-center gap-2 font-medium">
                Coincidencias <Spinner v-if="loading" />
            </h2>
            <p v-if="previewError" class="text-sm text-destructive">
                {{ previewError }}
            </p>
            <template v-else-if="result">
                <p class="text-sm">
                    <strong class="text-lg tabular-nums">{{
                        number(result.subscribed)
                    }}</strong>
                    suscritos recibirían una campaña dirigida a este segmento
                    ({{ number(result.total) }}
                    contactos en total, incluidos bajas y limpiados).
                </p>
                <ul
                    v-if="result.sample.length"
                    class="mt-3 divide-y text-sm"
                    aria-label="Muestra de contactos"
                >
                    <li
                        v-for="p in result.sample"
                        :key="p.id"
                        class="flex justify-between gap-2 py-1.5"
                    >
                        <span class="truncate"
                            >{{ p.email
                            }}<span v-if="p.name" class="text-muted-foreground">
                                · {{ p.name }}</span
                            ></span
                        >
                        <span class="shrink-0 text-muted-foreground">{{
                            p.status
                        }}</span>
                    </li>
                </ul>
            </template>
            <p v-else class="text-sm text-muted-foreground">
                Completa las condiciones para ver cuántos contactos coinciden.
            </p>
        </section>

        <div class="flex gap-2">
            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" />Guardar segmento
            </Button>
            <Button type="button" variant="ghost" @click="cancel"
                >Cancelar</Button
            >
        </div>
    </form>
</template>
