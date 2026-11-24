<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type {
    CampaignOption,
    SegmentCondition,
    SegmentFieldDef,
} from '@/types';

/**
 * Rows of {field, operator, value} conditions, shared by segments and automation
 * condition steps. Values are validated again on the server.
 */
const conditions = defineModel<SegmentCondition[]>({ required: true });

const props = withDefaults(
    defineProps<{
        catalog: SegmentFieldDef[];
        operators: Record<string, string>;
        tags: { id: number; name: string }[];
        campaigns?: CampaignOption[];
        idPrefix?: string;
    }>(),
    { campaigns: () => [], idPrefix: 'c' },
);

const NO_VALUE = ['blank', 'not_blank'];
const DAYS = [
    'within_days',
    'not_within_days',
    'opened_within_days',
    'not_opened_within_days',
    'clicked_within_days',
    'not_clicked_within_days',
];
const MONTHS = [
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

// Campaign conditions need a campaign list; hide them when none is given.
const available = computed(() =>
    props.catalog.filter(
        (f) => f.input !== 'campaign' || props.campaigns.length > 0,
    ),
);
const fields = computed(() => new Map(available.value.map((f) => [f.key, f])));
const groups = computed(() => [
    ...new Set(available.value.map((f) => f.group)),
]);

const def = (c: SegmentCondition) => fields.value.get(c.field);
const inputFor = (c: SegmentCondition) =>
    DAYS.includes(c.op) ? 'number' : (def(c)?.input ?? 'text');

const changeField = (c: SegmentCondition) => {
    c.op = def(c)?.ops[0] ?? 'is';
    c.value = null;
};
const add = () =>
    conditions.value.push({ field: 'tag', op: 'has', value: null });
const remove = (i: number) => conditions.value.splice(i, 1);
const historicSelected = computed(() =>
    conditions.value.some(
        (c) =>
            c.field === 'campaign' &&
            props.campaigns.find((x) => x.id === c.value)?.historic,
    ),
);
</script>

<template>
    <div class="space-y-2">
        <ol class="space-y-2">
            <li
                v-for="(c, i) in conditions"
                :key="i"
                class="grid gap-2 rounded-lg bg-muted/40 p-3 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-center"
            >
                <label class="sr-only" :for="`${idPrefix}-f-${i}`"
                    >Campo de la condición {{ i + 1 }}</label
                >
                <NativeSelect
                    :id="`${idPrefix}-f-${i}`"
                    v-model="c.field"
                    @change="changeField(c)"
                >
                    <optgroup v-for="g in groups" :key="g" :label="g">
                        <option
                            v-for="f in available.filter((x) => x.group === g)"
                            :key="f.key"
                            :value="f.key"
                        >
                            {{ f.label }}
                        </option>
                    </optgroup>
                </NativeSelect>

                <label class="sr-only" :for="`${idPrefix}-o-${i}`"
                    >Comparación de la condición {{ i + 1 }}</label
                >
                <NativeSelect :id="`${idPrefix}-o-${i}`" v-model="c.op">
                    <option
                        v-for="op in def(c)?.ops ?? []"
                        :key="op"
                        :value="op"
                    >
                        {{ operators[op] }}
                    </option>
                </NativeSelect>

                <div v-if="!NO_VALUE.includes(c.op)">
                    <label class="sr-only" :for="`${idPrefix}-v-${i}`"
                        >Valor de la condición {{ i + 1 }}</label
                    >
                    <NativeSelect
                        v-if="inputFor(c) === 'tag'"
                        :id="`${idPrefix}-v-${i}`"
                        v-model="c.value"
                    >
                        <option :value="null" disabled>Elige…</option>
                        <option v-for="t in tags" :key="t.id" :value="t.id">
                            {{ t.name }}
                        </option>
                    </NativeSelect>
                    <NativeSelect
                        v-else-if="inputFor(c) === 'campaign'"
                        :id="`${idPrefix}-v-${i}`"
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
                        :id="`${idPrefix}-v-${i}`"
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
                        :id="`${idPrefix}-v-${i}`"
                        v-model="c.value"
                    >
                        <option :value="null" disabled>Elige…</option>
                        <option
                            v-for="(m, mi) in MONTHS"
                            :key="m"
                            :value="mi + 1"
                        >
                            {{ m }}
                        </option>
                    </NativeSelect>
                    <Input
                        v-else
                        :id="`${idPrefix}-v-${i}`"
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
                    :disabled="conditions.length === 1"
                    :aria-label="`Quitar la condición ${i + 1}`"
                    @click="remove(i)"
                    ><Trash2 aria-hidden="true"
                /></Button>
            </li>
        </ol>
        <Button
            type="button"
            variant="secondary"
            size="sm"
            :disabled="conditions.length >= 20"
            @click="add"
            ><Plus aria-hidden="true" />Agregar condición</Button
        >
        <p v-if="historicSelected" class="text-xs text-muted-foreground">
            Las campañas de Mailchimp no guardan la actividad por persona: las
            condiciones de apertura o clic sobre ellas no encontrarán
            coincidencias.
        </p>
    </div>
</template>
