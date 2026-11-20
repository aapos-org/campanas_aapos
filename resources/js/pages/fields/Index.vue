<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { ListPlus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import MergeFieldController from '@/actions/App/Http/Controllers/Contacts/MergeFieldController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { destroy, index } from '@/routes/fields';
import type { Option } from '@/types';

type FieldRow = {
    id: number;
    key: string;
    label: string;
    type: string;
    type_label: string;
    options: string[];
    required: boolean;
    tag: string;
};

defineProps<{ fields: FieldRow[]; types: Option[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Campos personalizados', href: index() }],
    },
});

const type = ref('text');
const optionsText = ref('');
const required = ref(false);
const deleteTarget = ref<FieldRow | null>(null);

const confirmDelete = () => {
    if (deleteTarget.value) {
        router.delete(destroy.url(deleteTarget.value.id), {
            preserveScroll: true,
            onFinish: () => (deleteTarget.value = null),
        });
    }
};
</script>

<template>
    <Head title="Campos personalizados" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Campos personalizados"
            description="Datos adicionales de los contactos (cumpleaños, ciudad, tipo de socio…). Se usan para personalizar correos y crear segmentos."
        />

        <Form
            v-bind="MergeFieldController.store.form()"
            :transform="
                (data) => ({
                    ...data,
                    type,
                    required,
                    options: optionsText
                        .split('\n')
                        .map((o) => o.trim())
                        .filter(Boolean),
                })
            "
            :options="{ preserveScroll: true }"
            reset-on-success
            class="grid gap-4 rounded-xl border p-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end"
            @success="
                () => {
                    optionsText = '';
                    required = false;
                }
            "
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-1.5 lg:col-span-2">
                <Label for="label">Nombre del campo</Label>
                <Input
                    id="label"
                    name="label"
                    required
                    placeholder="Ej.: Ciudad"
                />
                <InputError :message="errors.label" />
            </div>
            <div class="grid gap-1.5">
                <Label for="type">Tipo</Label>
                <NativeSelect id="type" v-model="type">
                    <option v-for="t in types" :key="t.value" :value="t.value">
                        {{ t.label }}
                    </option>
                </NativeSelect>
                <InputError :message="errors.type" />
            </div>
            <div class="flex items-center gap-2 lg:pb-2">
                <Checkbox
                    id="required"
                    :model-value="required"
                    @update:model-value="(v) => (required = v === true)"
                />
                <Label for="required">Obligatorio</Label>
            </div>
            <div
                v-if="type === 'select'"
                class="grid gap-1.5 sm:col-span-2 lg:col-span-4"
            >
                <Label for="options">Opciones (una por línea)</Label>
                <textarea
                    id="options"
                    v-model="optionsText"
                    class="min-h-24 rounded-md border border-input bg-transparent px-3 py-2 text-sm dark:bg-input/30"
                />
                <InputError :message="errors.options" />
            </div>
            <div class="sm:col-span-2 lg:col-span-4">
                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    <ListPlus v-else aria-hidden="true" />
                    Agregar campo
                </Button>
            </div>
        </Form>

        <EmptyState
            v-if="fields.length === 0"
            :icon="ListPlus"
            title="Sin campos personalizados"
            description="El correo, nombre, apellido y teléfono ya vienen incluidos."
        />

        <ul v-else class="grid gap-3">
            <li
                v-for="field in fields"
                :key="field.id"
                class="flex flex-col gap-2 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="min-w-0 space-y-1">
                    <p class="font-medium">
                        {{ field.label }}
                        <Badge
                            v-if="field.required"
                            variant="outline"
                            class="ml-1"
                            >Obligatorio</Badge
                        >
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ field.type_label }} · variable
                        <code class="rounded bg-muted px-1">{{
                            field.tag
                        }}</code>
                    </p>
                    <p
                        v-if="field.options.length"
                        class="text-sm text-muted-foreground"
                    >
                        Opciones: {{ field.options.join(', ') }}
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="sm"
                    class="self-start text-destructive"
                    @click="deleteTarget = field"
                >
                    <Trash2 aria-hidden="true" />
                    Eliminar
                </Button>
            </li>
        </ul>
    </div>

    <ConfirmDialog
        :open="deleteTarget !== null"
        title="¿Eliminar el campo?"
        :description="`Se quitará «${deleteTarget?.label}» de los formularios. Los valores ya guardados se conservan en los contactos.`"
        confirm-label="Eliminar"
        destructive
        @update:open="(v) => !v && (deleteTarget = null)"
        @confirm="confirmDelete"
    />
</template>
