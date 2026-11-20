<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    Check,
    Copy,
    ExternalLink,
    Trash2,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { destroy, index, store, update } from '@/routes/forms';

type FieldConfig = { key: string; label: string; required: boolean };
type AvailableField = { key: string; label: string; type: string };

const props = defineProps<{
    form: {
        id: number;
        name: string;
        audience_id: number;
        is_active: boolean;
        fields: FieldConfig[];
        texts: Record<string, string>;
        style: Record<string, string>;
        double_opt_in: boolean | null;
        captcha: boolean;
        tags: string[];
        redirect_url: string | null;
        submissions_count: number;
    } | null;
    defaults: { texts: Record<string, string>; style: Record<string, string> };
    audiences: { id: number; name: string; double_opt_in: boolean }[];
    availableFields: AvailableField[];
    turnstileConfigured: boolean;
    embed: { url: string; iframe: string; script: string; html: string } | null;
    preselectedAudience: number | null;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Formularios', href: index() }] },
});

const draft = reactive({
    name: props.form?.name ?? 'Suscripción al boletín',
    audience_id: String(
        props.form?.audience_id ??
            props.preselectedAudience ??
            props.audiences[0]?.id ??
            '',
    ),
    is_active: props.form?.is_active ?? true,
    fields: (
        props.form?.fields ?? [
            { key: 'email', label: 'Correo electrónico', required: true },
            { key: 'first_name', label: 'Nombre', required: false },
        ]
    ).map((f) => ({ ...f })),
    texts: { ...props.defaults.texts, ...props.form?.texts },
    style: { ...props.defaults.style, ...props.form?.style },
    double_opt_in:
        props.form?.double_opt_in === null || props.form === null
            ? 'inherit'
            : props.form.double_opt_in
              ? 'yes'
              : 'no',
    tags: (props.form?.tags ?? []).join(', '),
});

const captcha = ref<boolean>(props.form?.captcha ?? false);
const redirectUrl = ref<string>(props.form?.redirect_url ?? '');
const errors = ref<Record<string, string>>({});
const processing = ref(false);
const previewKey = ref(0);

const selectedAudience = computed(() =>
    props.audiences.find((a) => String(a.id) === draft.audience_id),
);
const unusedFields = computed(() =>
    props.availableFields.filter(
        (f) => !draft.fields.some((s) => s.key === f.key),
    ),
);

const addField = (field: AvailableField) =>
    draft.fields.push({ key: field.key, label: field.label, required: false });
const removeField = (i: number) => draft.fields.splice(i, 1);
const move = (i: number, delta: number) => {
    const target = i + delta;
    if (target < 1 || target >= draft.fields.length) {
        return; // the email stays first
    }
    const [item] = draft.fields.splice(i, 1);
    draft.fields.splice(target, 0, item);
};

const textLabels: Record<string, string> = {
    title: 'Título',
    description: 'Descripción',
    button: 'Texto del botón',
    success: 'Mensaje tras el alta simple',
    pending: 'Mensaje «revisa tu correo» (doble opt-in)',
    confirmed: 'Mensaje al confirmar',
    already: 'Mensaje «ya estabas suscrito»',
    confirmation_subject: 'Asunto del correo de confirmación',
    confirmation_intro: 'Texto del correo de confirmación',
};

const styleLabels: Record<string, string> = {
    primary_color: 'Color principal',
    background_color: 'Fondo',
    text_color: 'Texto',
};

const submit = () => {
    const payload = {
        name: draft.name,
        audience_id: Number(draft.audience_id),
        is_active: draft.is_active,
        fields: draft.fields,
        texts: draft.texts,
        style: draft.style,
        double_opt_in:
            draft.double_opt_in === 'inherit'
                ? null
                : draft.double_opt_in === 'yes',
        captcha: captcha.value,
        tags: draft.tags
            .split(',')
            .map((t) => t.trim())
            .filter(Boolean),
        redirect_url: redirectUrl.value || null,
    };
    const options = {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
        onError: (e: Record<string, string>) => (errors.value = e),
        onSuccess: () => {
            errors.value = {};
            previewKey.value++;
        },
    };

    if (props.form) {
        router.put(update.url(props.form.id), payload, options);
    } else {
        router.post(store.url(), payload, options);
    }
};

const copied = ref<string | null>(null);
const copy = async (key: string, value: string) => {
    await navigator.clipboard.writeText(value);
    copied.value = key;
    setTimeout(() => (copied.value = null), 2000);
};
const embedTab = ref<'url' | 'iframe' | 'script' | 'html'>('script');
const embedTabs = [
    { key: 'script', label: 'JavaScript (recomendado)' },
    { key: 'iframe', label: 'iframe' },
    { key: 'html', label: 'HTML' },
    { key: 'url', label: 'Enlace' },
] as const;

const confirmingDelete = ref(false);
const remove = () => props.form && router.delete(destroy.url(props.form.id));
const textareaClass =
    'min-h-16 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs dark:bg-input/30';
</script>

<template>
    <Head :title="form ? form.name : 'Nuevo formulario'" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                :title="form ? form.name : 'Nuevo formulario'"
                :description="
                    form
                        ? `${form.submissions_count} envíos recibidos`
                        : undefined
                "
            />
            <div class="flex gap-2">
                <Button
                    v-if="embed"
                    as="a"
                    :href="embed.url"
                    target="_blank"
                    rel="noopener"
                    variant="secondary"
                    size="sm"
                >
                    <ExternalLink aria-hidden="true" />
                    Abrir
                </Button>
                <Button
                    v-if="form"
                    variant="ghost"
                    size="sm"
                    class="text-destructive"
                    @click="confirmingDelete = true"
                >
                    <Trash2 aria-hidden="true" />
                    Eliminar
                </Button>
            </div>
        </div>

        <div class="grid gap-8 xl:grid-cols-[minmax(0,1fr)_420px]">
            <form class="space-y-8" @submit.prevent="submit">
                <section class="space-y-4" aria-labelledby="s-basic">
                    <h2 id="s-basic" class="font-medium">Datos básicos</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="name">Nombre interno</Label>
                            <Input id="name" v-model="draft.name" required />
                            <InputError :message="errors.name" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="audience">Audiencia</Label>
                            <NativeSelect
                                id="audience"
                                v-model="draft.audience_id"
                                required
                            >
                                <option
                                    v-for="a in audiences"
                                    :key="a.id"
                                    :value="String(a.id)"
                                >
                                    {{ a.name }}
                                </option>
                            </NativeSelect>
                            <InputError :message="errors.audience_id" />
                        </div>
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox
                            :model-value="draft.is_active"
                            @update:model-value="
                                (v) => (draft.is_active = v === true)
                            "
                        />
                        Formulario activo (si lo desactivas, sus enlaces y
                        códigos dejan de funcionar)
                    </label>
                </section>

                <section class="space-y-3" aria-labelledby="s-fields">
                    <h2 id="s-fields" class="font-medium">Campos</h2>
                    <InputError :message="errors.fields" />
                    <ul class="grid gap-2">
                        <li
                            v-for="(field, i) in draft.fields"
                            :key="field.key"
                            class="grid gap-2 rounded-lg border p-3 sm:grid-cols-[1fr_auto_auto] sm:items-center"
                        >
                            <div class="grid gap-1">
                                <Label
                                    :for="`label-${field.key}`"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{
                                        availableFields.find(
                                            (f) => f.key === field.key,
                                        )?.label ?? field.key
                                    }}
                                </Label>
                                <Input
                                    :id="`label-${field.key}`"
                                    v-model="field.label"
                                />
                            </div>
                            <label class="flex items-center gap-2 text-sm">
                                <Checkbox
                                    :model-value="field.required"
                                    :disabled="field.key === 'email'"
                                    @update:model-value="
                                        (v) => (field.required = v === true)
                                    "
                                />
                                Obligatorio
                            </label>
                            <div
                                v-if="field.key !== 'email'"
                                class="flex gap-1"
                            >
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Subir ${field.label}`"
                                    :disabled="i <= 1"
                                    @click="move(i, -1)"
                                    ><ArrowUp
                                /></Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Bajar ${field.label}`"
                                    :disabled="i === draft.fields.length - 1"
                                    @click="move(i, 1)"
                                    ><ArrowDown
                                /></Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="text-destructive"
                                    :aria-label="`Quitar ${field.label}`"
                                    @click="removeField(i)"
                                    ><Trash2
                                /></Button>
                            </div>
                        </li>
                    </ul>
                    <div
                        v-if="unusedFields.length"
                        class="flex flex-wrap gap-2"
                    >
                        <span class="self-center text-sm text-muted-foreground"
                            >Agregar:</span
                        >
                        <Button
                            v-for="f in unusedFields"
                            :key="f.key"
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="addField(f)"
                            >+ {{ f.label }}</Button
                        >
                    </div>
                </section>

                <section class="space-y-4" aria-labelledby="s-texts">
                    <h2 id="s-texts" class="font-medium">Textos</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div
                            v-for="(label, key) in textLabels"
                            :key="key"
                            class="grid gap-1.5"
                            :class="{
                                'sm:col-span-2': [
                                    'description',
                                    'confirmation_intro',
                                ].includes(key),
                            }"
                        >
                            <Label :for="`t-${key}`">{{ label }}</Label>
                            <textarea
                                v-if="
                                    [
                                        'description',
                                        'confirmation_intro',
                                        'pending',
                                    ].includes(key)
                                "
                                :id="`t-${key}`"
                                v-model="draft.texts[key]"
                                :class="textareaClass"
                            />
                            <Input
                                v-else
                                :id="`t-${key}`"
                                v-model="draft.texts[key]"
                            />
                        </div>
                    </div>
                </section>

                <section class="space-y-4" aria-labelledby="s-style">
                    <h2 id="s-style" class="font-medium">Estilo</h2>
                    <div class="flex flex-wrap gap-6">
                        <label
                            v-for="(label, key) in styleLabels"
                            :key="key"
                            class="flex items-center gap-2 text-sm"
                        >
                            <input
                                v-model="draft.style[key]"
                                type="color"
                                class="h-9 w-12 cursor-pointer rounded border"
                            />
                            {{ label }}
                        </label>
                    </div>
                    <InputError
                        :message="
                            Object.entries(errors).find(([k]) =>
                                k.startsWith('style'),
                            )?.[1]
                        "
                    />
                </section>

                <section class="space-y-4" aria-labelledby="s-options">
                    <h2 id="s-options" class="font-medium">Opciones</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="doi"
                                >Confirmación por correo (doble opt-in)</Label
                            >
                            <NativeSelect
                                id="doi"
                                v-model="draft.double_opt_in"
                            >
                                <option value="inherit">
                                    Igual que la audiencia ({{
                                        selectedAudience?.double_opt_in
                                            ? 'sí'
                                            : 'no'
                                    }})
                                </option>
                                <option value="yes">Sí, siempre</option>
                                <option value="no">No (alta simple)</option>
                            </NativeSelect>
                            <p class="text-xs text-muted-foreground">
                                Quien se haya dado de baja o rebotado siempre
                                debe confirmar.
                            </p>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="tags"
                                >Etiquetas para quien se suscriba</Label
                            >
                            <Input
                                id="tags"
                                v-model="draft.tags"
                                placeholder="Ej.: Web, Boletín"
                            />
                        </div>
                        <div class="grid gap-1.5 sm:col-span-2">
                            <Label for="redirect"
                                >Página de destino tras suscribirse
                                (opcional)</Label
                            >
                            <Input
                                id="redirect"
                                v-model="redirectUrl"
                                type="url"
                                placeholder="https://tu-sitio.org/gracias"
                            />
                            <InputError :message="errors.redirect_url" />
                        </div>
                    </div>
                    <label class="flex items-start gap-2 text-sm">
                        <Checkbox
                            :model-value="captcha"
                            :disabled="!turnstileConfigured"
                            @update:model-value="(v) => (captcha = v === true)"
                        />
                        <span>
                            Captcha de Cloudflare Turnstile
                            <span
                                v-if="!turnstileConfigured"
                                class="block text-xs text-muted-foreground"
                                >Configura TURNSTILE_SITE_KEY y
                                TURNSTILE_SECRET_KEY en el servidor para
                                activarlo. El honeypot y el límite de envíos ya
                                están activos.</span
                            >
                        </span>
                    </label>
                </section>

                <div class="flex flex-col-reverse gap-3 sm:flex-row">
                    <Button variant="outline" as-child
                        ><Link :href="index()">Volver</Link></Button
                    >
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        {{ form ? 'Guardar cambios' : 'Crear formulario' }}
                    </Button>
                </div>
            </form>

            <aside class="space-y-6">
                <section
                    v-if="embed"
                    class="space-y-3"
                    aria-labelledby="s-embed"
                >
                    <h2 id="s-embed" class="font-medium">Publicar</h2>
                    <div class="flex flex-wrap gap-1" role="tablist">
                        <Button
                            v-for="t in embedTabs"
                            :key="t.key"
                            type="button"
                            size="sm"
                            role="tab"
                            :aria-selected="embedTab === t.key"
                            :variant="
                                embedTab === t.key ? 'secondary' : 'ghost'
                            "
                            @click="embedTab = t.key"
                            >{{ t.label }}</Button
                        >
                    </div>
                    <pre
                        class="max-h-56 overflow-auto rounded-md bg-muted p-3 text-xs break-all whitespace-pre-wrap"
                        >{{ embed[embedTab] }}</pre>
                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        @click="copy(embedTab, embed[embedTab])"
                    >
                        <Check v-if="copied === embedTab" aria-hidden="true" />
                        <Copy v-else aria-hidden="true" />
                        {{ copied === embedTab ? 'Copiado' : 'Copiar' }}
                    </Button>
                    <p
                        v-if="embedTab === 'html'"
                        class="text-xs text-muted-foreground"
                    >
                        El código HTML usa los estilos de tu sitio y no lleva
                        captcha; el de JavaScript o iframe es más seguro.
                    </p>
                </section>

                <section
                    v-if="embed"
                    class="space-y-2"
                    aria-labelledby="s-preview"
                >
                    <h2 id="s-preview" class="font-medium">
                        Vista previa (versión guardada)
                    </h2>
                    <iframe
                        :key="previewKey"
                        :src="`${embed.url}?embed=1`"
                        title="Vista previa del formulario"
                        class="h-[560px] w-full rounded-lg border bg-muted"
                    />
                </section>

                <Alert v-else>
                    <AlertDescription
                        >Guarda el formulario para obtener el código de
                        inserción y la vista previa.</AlertDescription
                    >
                </Alert>
            </aside>
        </div>
    </div>

    <ConfirmDialog
        v-model:open="confirmingDelete"
        title="¿Eliminar el formulario?"
        description="Los sitios donde esté insertado dejarán de mostrarlo. Los contactos ya suscritos no cambian."
        confirm-label="Eliminar"
        destructive
        @confirm="remove"
    />
</template>
