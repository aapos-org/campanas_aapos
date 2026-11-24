<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    Clock,
    Filter,
    Mail,
    Pause,
    Pencil,
    Play,
    Tag,
    Trash2,
} from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import ConditionBuilder from '@/components/segments/ConditionBuilder.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    activate,
    destroy,
    index,
    pause,
    show,
    update,
} from '@/routes/automations';
import { content } from '@/routes/campaigns';
import type { SegmentCondition, SegmentFieldDef } from '@/types';

type Step = {
    id?: number;
    type: string;
    config: Record<string, unknown>;
    campaign_id?: number | null;
    has_content?: boolean;
};

const props = defineProps<{
    automation: {
        id: number;
        name: string;
        audience_id: number;
        trigger_type: string;
        trigger_config: Record<string, unknown>;
        status: 'draft' | 'active' | 'paused';
        allow_reentry: boolean;
        mail_connection_id: number | null;
        from_name: string | null;
        from_email: string | null;
        reply_to: string | null;
        steps: Step[];
    };
    problems: string[];
    audiences: { id: number; name: string }[];
    tags: { id: number; name: string }[];
    dateFields: { key: string; label: string }[];
    connections: {
        id: number;
        name: string;
        from_email: string;
        status: string;
    }[];
    triggers: { value: string; label: string }[];
    stepTypes: { value: string; label: string }[];
    catalog: SegmentFieldDef[];
    operators: Record<string, string>;
    canSend: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Automatizaciones', href: index() }] },
});

const form = reactive({
    name: props.automation.name,
    audience_id: props.automation.audience_id,
    trigger_type: props.automation.trigger_type,
    trigger_config: {
        tag_id: null,
        field_key: null,
        days_before: 0,
        send_hour: 9,
        ...props.automation.trigger_config,
    } as {
        tag_id: number | null;
        field_key: string | null;
        days_before: number;
        send_hour: number;
    },
    allow_reentry: props.automation.allow_reentry,
    mail_connection_id: props.automation.mail_connection_id,
    from_name: props.automation.from_name ?? '',
    from_email: props.automation.from_email ?? '',
    reply_to: props.automation.reply_to ?? '',
    steps: props.automation.steps.map((s) => ({
        ...s,
        config: { ...s.config },
    })) as Step[],
});
const errors = ref<Record<string, string>>({});
const saving = ref(false);
const confirmingDelete = ref(false);
const active = computed(() => props.automation.status === 'active');

const defaults: Record<string, Record<string, unknown>> = {
    send_email: { subject: '', preheader: '' },
    wait: { amount: 1, unit: 'days' },
    add_tag: { tag_id: null },
    remove_tag: { tag_id: null },
    condition: {
        match: 'all',
        conditions: [{ field: 'tag', op: 'has', value: null }],
        on_false: 'exit',
    },
};
const icons: Record<string, unknown> = {
    send_email: Mail,
    wait: Clock,
    add_tag: Tag,
    remove_tag: Tag,
    condition: Filter,
};
const typeLabel = (t: string) =>
    props.stepTypes.find((x) => x.value === t)?.label ?? t;

const addStep = (type: string) =>
    form.steps.push({ type, config: structuredClone(defaults[type]) });
const move = (i: number, delta: number) => {
    const [step] = form.steps.splice(i, 1);
    form.steps.splice(i + delta, 0, step);
};
const removeStep = (i: number) => form.steps.splice(i, 1);

const save = (then?: () => void) =>
    router.put(
        update.url(props.automation.id),
        {
            ...form,
            mail_connection_id: form.mail_connection_id || null,
            from_name: form.from_name || null,
            from_email: form.from_email || null,
            reply_to: form.reply_to || null,
            steps: form.steps.map((s) => ({
                id: s.id ?? null,
                type: s.type,
                config: s.config,
            })),
        } as never,
        {
            preserveScroll: true,
            onStart: () => (saving.value = true),
            onFinish: () => (saving.value = false),
            onError: (e) => (errors.value = e),
            onSuccess: () => {
                errors.value = {};
                then?.();
            },
        },
    );

// After each save the server returns the stored steps (with ids and email
// campaigns): mirror them so later saves update instead of recreating steps.
watch(
    () => props.automation.steps,
    (steps) => {
        form.steps = steps.map((st) => ({ ...st, config: { ...st.config } }));
    },
);

const editContent = (index: number) =>
    save(() => {
        const saved = props.automation.steps[index];

        if (saved?.campaign_id) {
            window.location.href = content.url(saved.campaign_id);
        }
    });

const statusLabel = { draft: 'Borrador', active: 'Activa', paused: 'En pausa' };
</script>

<template>
    <Head :title="`Automatización · ${automation.name}`" />

    <form
        class="flex flex-1 flex-col gap-6 p-4 md:p-6"
        @submit.prevent="save()"
    >
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                :title="automation.name"
                description="Configura qué inicia el flujo y los pasos que sigue cada contacto."
            />
            <div class="flex flex-wrap items-center gap-2">
                <Badge :variant="active ? 'default' : 'outline'">{{
                    statusLabel[automation.status]
                }}</Badge>
                <Button variant="ghost" size="sm" as-child
                    ><Link :href="show(automation.id)"
                        >Ver actividad</Link
                    ></Button
                >
                <Button
                    v-if="active"
                    type="button"
                    variant="secondary"
                    size="sm"
                    @click="router.post(pause.url(automation.id))"
                    ><Pause aria-hidden="true" />Pausar</Button
                >
                <Button
                    v-else-if="canSend"
                    type="button"
                    size="sm"
                    :disabled="problems.length > 0"
                    @click="
                        router.post(
                            activate.url(automation.id),
                            {},
                            { onError: (e) => (errors = e) },
                        )
                    "
                    ><Play aria-hidden="true" />Activar</Button
                >
            </div>
        </div>

        <Alert v-if="problems.length && !active">
            <AlertTitle>Antes de activar</AlertTitle>
            <AlertDescription>
                <ul class="list-disc pl-4">
                    <li v-for="p in problems" :key="p">{{ p }}</li>
                </ul>
            </AlertDescription>
        </Alert>
        <InputError :message="errors.activation" />

        <section class="grid gap-4 sm:grid-cols-2" aria-labelledby="s-general">
            <h2 id="s-general" class="font-medium sm:col-span-2">General</h2>
            <div class="grid gap-1.5">
                <Label for="name">Nombre</Label>
                <Input id="name" v-model="form.name" required />
                <InputError :message="errors.name" />
            </div>
            <div class="grid gap-1.5">
                <Label for="audience">Audiencia</Label>
                <NativeSelect
                    id="audience"
                    v-model="form.audience_id"
                    :disabled="active"
                >
                    <option v-for="a in audiences" :key="a.id" :value="a.id">
                        {{ a.name }}
                    </option>
                </NativeSelect>
            </div>
        </section>

        <section class="grid gap-4" aria-labelledby="s-trigger">
            <h2 id="s-trigger" class="font-medium">Disparador</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="trigger">Se inicia cuando…</Label>
                    <NativeSelect
                        id="trigger"
                        v-model="form.trigger_type"
                        :disabled="active"
                    >
                        <option
                            v-for="t in triggers"
                            :key="t.value"
                            :value="t.value"
                        >
                            {{ t.label }}
                        </option>
                    </NativeSelect>
                    <InputError :message="errors.trigger_type" />
                </div>
                <div
                    v-if="form.trigger_type === 'tag_added'"
                    class="grid gap-1.5"
                >
                    <Label for="t-tag">Etiqueta</Label>
                    <NativeSelect
                        id="t-tag"
                        v-model="form.trigger_config.tag_id"
                    >
                        <option :value="null" disabled>Elige…</option>
                        <option v-for="t in tags" :key="t.id" :value="t.id">
                            {{ t.name }}
                        </option>
                    </NativeSelect>
                    <InputError :message="errors['trigger_config.tag_id']" />
                </div>
            </div>
            <div
                v-if="form.trigger_type === 'date_field'"
                class="grid gap-4 sm:grid-cols-3"
            >
                <div class="grid gap-1.5">
                    <Label for="t-field">Campo de fecha</Label>
                    <NativeSelect
                        id="t-field"
                        v-model="form.trigger_config.field_key"
                    >
                        <option :value="null" disabled>Elige…</option>
                        <option
                            v-for="f in dateFields"
                            :key="f.key"
                            :value="f.key"
                        >
                            {{ f.label }}
                        </option>
                    </NativeSelect>
                    <InputError :message="errors['trigger_config.field_key']" />
                    <p
                        v-if="!dateFields.length"
                        class="text-xs text-muted-foreground"
                    >
                        Crea primero un campo personalizado de tipo fecha o
                        cumpleaños.
                    </p>
                </div>
                <div class="grid gap-1.5">
                    <Label for="t-days">Días antes de la fecha</Label>
                    <Input
                        id="t-days"
                        v-model.number="form.trigger_config.days_before"
                        type="number"
                        min="0"
                        max="60"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="t-hour">Hora de envío (0–23)</Label>
                    <Input
                        id="t-hour"
                        v-model.number="form.trigger_config.send_hour"
                        type="number"
                        min="0"
                        max="23"
                    />
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm"
                ><Checkbox
                    :model-value="form.allow_reentry"
                    @update:model-value="
                        (v) => (form.allow_reentry = v === true)
                    "
                />Permitir que un contacto vuelva a entrar (por ejemplo, cada
                año en su cumpleaños)</label
            >
            <p class="text-xs text-muted-foreground">
                Solo cuentan los eventos posteriores a la activación. Si se da
                de baja, el contacto sale del flujo.
            </p>
        </section>

        <section class="grid gap-4 sm:grid-cols-2" aria-labelledby="s-from">
            <h2 id="s-from" class="font-medium sm:col-span-2">Remitente</h2>
            <div class="grid gap-1.5">
                <Label for="connection">Conexión de envío</Label>
                <NativeSelect id="connection" v-model="form.mail_connection_id">
                    <option :value="null">— Elige —</option>
                    <option v-for="c in connections" :key="c.id" :value="c.id">
                        {{ c.name }} ({{ c.from_email }})
                    </option>
                </NativeSelect>
                <InputError :message="errors.mail_connection_id" />
            </div>
            <div class="grid gap-1.5">
                <Label for="from_name">Nombre del remitente</Label>
                <Input id="from_name" v-model="form.from_name" />
            </div>
            <div class="grid gap-1.5">
                <Label for="from_email">Correo del remitente</Label>
                <Input id="from_email" v-model="form.from_email" type="email" />
                <InputError :message="errors.from_email" />
            </div>
            <div class="grid gap-1.5">
                <Label for="reply_to">Responder a</Label>
                <Input id="reply_to" v-model="form.reply_to" type="email" />
            </div>
        </section>

        <section class="grid gap-3" aria-labelledby="s-steps">
            <h2 id="s-steps" class="font-medium">Pasos</h2>
            <ol class="grid gap-3">
                <li
                    v-for="(s, i) in form.steps"
                    :key="s.id ?? `new-${i}`"
                    class="space-y-3 rounded-xl border p-4"
                >
                    <div class="flex items-center justify-between gap-2">
                        <p class="flex items-center gap-2 font-medium">
                            <component
                                :is="icons[s.type]"
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            {{ i + 1 }}. {{ typeLabel(s.type) }}
                        </p>
                        <div class="flex gap-1">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :disabled="i === 0"
                                :aria-label="`Subir el paso ${i + 1}`"
                                @click="move(i, -1)"
                                ><ArrowUp aria-hidden="true"
                            /></Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :disabled="i === form.steps.length - 1"
                                :aria-label="`Bajar el paso ${i + 1}`"
                                @click="move(i, 1)"
                                ><ArrowDown aria-hidden="true"
                            /></Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :aria-label="`Quitar el paso ${i + 1}`"
                                @click="removeStep(i)"
                                ><Trash2 aria-hidden="true"
                            /></Button>
                        </div>
                    </div>

                    <div
                        v-if="s.type === 'send_email'"
                        class="grid gap-3 sm:grid-cols-2"
                    >
                        <div class="grid gap-1.5">
                            <Label :for="`s-subj-${i}`">Asunto</Label>
                            <Input
                                :id="`s-subj-${i}`"
                                v-model="s.config.subject as string"
                                maxlength="200"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label :for="`s-pre-${i}`"
                                >Texto de vista previa (opcional)</Label
                            >
                            <Input
                                :id="`s-pre-${i}`"
                                v-model="s.config.preheader as string"
                                maxlength="200"
                            />
                        </div>
                        <div class="flex items-center gap-2 sm:col-span-2">
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                @click="editContent(i)"
                                ><Pencil aria-hidden="true" />{{
                                    s.id
                                        ? 'Diseñar el correo'
                                        : 'Guardar para diseñar'
                                }}</Button
                            >
                            <span
                                class="text-xs"
                                :class="
                                    s.has_content
                                        ? 'text-muted-foreground'
                                        : 'text-destructive'
                                "
                                >{{
                                    s.has_content
                                        ? 'Tiene contenido.'
                                        : 'Aún sin contenido.'
                                }}</span
                            >
                        </div>
                    </div>

                    <div
                        v-else-if="s.type === 'wait'"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <label class="sr-only" :for="`s-amt-${i}`"
                            >Cantidad</label
                        >
                        <Input
                            :id="`s-amt-${i}`"
                            v-model.number="s.config.amount as number"
                            type="number"
                            min="1"
                            max="365"
                            class="w-24"
                        />
                        <label class="sr-only" :for="`s-unit-${i}`"
                            >Unidad</label
                        >
                        <NativeSelect
                            :id="`s-unit-${i}`"
                            v-model="s.config.unit as string"
                            class="w-32"
                        >
                            <option value="minutes">minutos</option>
                            <option value="hours">horas</option>
                            <option value="days">días</option>
                        </NativeSelect>
                    </div>

                    <div
                        v-else-if="
                            s.type === 'add_tag' || s.type === 'remove_tag'
                        "
                        class="grid gap-1.5 sm:max-w-sm"
                    >
                        <Label :for="`s-tag-${i}`">Etiqueta</Label>
                        <NativeSelect
                            :id="`s-tag-${i}`"
                            v-model="s.config.tag_id as number | null"
                        >
                            <option :value="null" disabled>Elige…</option>
                            <option v-for="t in tags" :key="t.id" :value="t.id">
                                {{ t.name }}
                            </option>
                        </NativeSelect>
                    </div>

                    <div v-else-if="s.type === 'condition'" class="space-y-3">
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span>Continúa si cumple</span>
                            <select
                                v-model="s.config.match"
                                class="h-9 rounded-md border border-input bg-transparent px-2 text-sm dark:bg-input/30"
                                :aria-label="`Coincidencia del paso ${i + 1}`"
                            >
                                <option value="all">
                                    todas las condiciones
                                </option>
                                <option value="any">al menos una</option>
                            </select>
                        </div>
                        <ConditionBuilder
                            v-model="s.config.conditions as SegmentCondition[]"
                            :catalog="catalog"
                            :operators="operators"
                            :tags="tags"
                            :id-prefix="`s${i}`"
                        />
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span>Si no la cumple:</span>
                            <select
                                v-model="s.config.on_false"
                                class="h-9 rounded-md border border-input bg-transparent px-2 text-sm dark:bg-input/30"
                                :aria-label="`Qué hacer si no se cumple el paso ${i + 1}`"
                            >
                                <option value="exit">sale del flujo</option>
                                <option value="skip">
                                    se salta el paso siguiente
                                </option>
                            </select>
                        </div>
                    </div>
                </li>
            </ol>
            <InputError :message="errors.steps" />

            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="t in stepTypes"
                    :key="t.value"
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="form.steps.length >= 30"
                    @click="addStep(t.value)"
                    ><component :is="icons[t.value]" aria-hidden="true" />{{
                        t.label
                    }}</Button
                >
            </div>
        </section>

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-between">
            <Button type="submit" :disabled="saving"
                ><Spinner v-if="saving" />Guardar</Button
            >
            <Button
                v-if="!active"
                type="button"
                variant="ghost"
                class="text-destructive"
                @click="confirmingDelete = true"
                ><Trash2 aria-hidden="true" />Eliminar automatización</Button
            >
        </div>
    </form>

    <ConfirmDialog
        v-model:open="confirmingDelete"
        title="¿Eliminar la automatización?"
        description="Se eliminarán los pasos y el registro de contactos. Los reportes de los correos ya enviados se conservan."
        confirm-label="Eliminar"
        destructive
        @confirm="router.delete(destroy.url(automation.id))"
    />
</template>
