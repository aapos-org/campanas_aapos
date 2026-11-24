<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import ConditionBuilder from '@/components/segments/ConditionBuilder.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { postJson } from '@/lib/http';
import { index, preview, store, update } from '@/routes/segments';
import type {
    CampaignOption,
    SegmentCondition,
    SegmentFieldDef,
} from '@/types';

type Condition = SegmentCondition;
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
    catalog: SegmentFieldDef[];
    operators: Record<string, string>;
    tags: { id: number; name: string }[];
    campaigns: CampaignOption[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Segmentos', href: index() }] },
});

const { number } = useFormat();
const NO_VALUE = ['blank', 'not_blank'];

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

        <fieldset class="space-y-3 glass-card p-4">
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

            <ConditionBuilder
                v-model="form.conditions"
                :catalog="catalog"
                :operators="operators"
                :tags="tags"
                :campaigns="campaigns"
            />
            <InputError :message="form.errors.conditions" />
        </fieldset>

        <section
            class="glass-card p-4"
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
