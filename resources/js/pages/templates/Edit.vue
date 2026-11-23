<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Head, Link, router } from '@inertiajs/vue3';
import { Copy, Save, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmailEditor from '@/components/email/EmailEditor.vue';
import HtmlEditor from '@/components/email/HtmlEditor.vue';
import MergeTagList from '@/components/email/MergeTagList.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { destroy, duplicate, index, update } from '@/routes/templates';

const props = defineProps<{
    template: {
        id: number;
        name: string;
        description: string | null;
        editor: 'visual' | 'html';
        mjml: string | null;
        design: Record<string, unknown> | null;
        html: string | null;
        source: string;
    };
    mergeTags: { tag: string; label: string }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Plantillas', href: index() }] },
});

const name = ref(props.template.name);
const description = ref(props.template.description ?? '');
const html = ref(props.template.html ?? '');
const visual = ref<InstanceType<typeof EmailEditor> | null>(null);
const saving = ref(false);
const errors = ref<Record<string, string>>({});
const confirmingDelete = ref(false);

const save = () => {
    const content =
        props.template.editor === 'visual'
            ? visual.value?.getContent()
            : { html: html.value, mjml: null, design: null };

    if (!content) {
        return;
    }

    const payload: Record<string, FormDataConvertible> = {
        name: name.value,
        description: description.value || null,
        html: content.html,
        mjml: content.mjml,
        design: content.design as unknown as FormDataConvertible,
    };

    router.put(update.url(props.template.id), payload, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => (saving.value = true),
        onFinish: () => (saving.value = false),
        onError: (e) => (errors.value = e),
    });
};
</script>

<template>
    <Head :title="template.name" />

    <div class="flex flex-1 flex-col gap-4 p-4 md:p-6">
        <div class="grid gap-3 lg:grid-cols-[1fr_1fr_auto] lg:items-end">
            <div class="grid gap-1.5">
                <Label for="name">Nombre</Label>
                <Input id="name" v-model="name" />
                <InputError :message="errors.name" />
            </div>
            <div class="grid gap-1.5">
                <Label for="description">Descripción</Label>
                <Input id="description" v-model="description" />
            </div>
            <div class="flex flex-wrap gap-2">
                <Button :disabled="saving" @click="save"
                    ><Spinner v-if="saving" /><Save
                        v-else
                        aria-hidden="true"
                    />Guardar</Button
                >
                <Button
                    variant="secondary"
                    @click="router.post(duplicate.url(template.id))"
                    ><Copy aria-hidden="true" />Duplicar</Button
                >
                <Button
                    variant="ghost"
                    class="text-destructive"
                    @click="confirmingDelete = true"
                    ><Trash2 aria-hidden="true" />Eliminar</Button
                >
            </div>
        </div>

        <MergeTagList :tags="mergeTags" />

        <div
            v-if="template.editor === 'visual'"
            class="h-[calc(100vh-260px)] min-h-[600px]"
        >
            <EmailEditor
                ref="visual"
                :mjml="template.mjml"
                :design="template.design"
            />
        </div>
        <HtmlEditor v-else v-model="html" />

        <p class="text-xs text-muted-foreground">
            Al guardar se eliminan scripts y código peligroso, y los estilos se
            aplican en línea para que el correo se vea bien en todos los
            clientes.
            <Link :href="index()" class="underline">Volver a plantillas</Link>
        </p>
    </div>

    <ConfirmDialog
        v-model:open="confirmingDelete"
        title="¿Eliminar la plantilla?"
        description="Las campañas que ya la usaron conservan su propia copia del contenido."
        confirm-label="Eliminar"
        destructive
        @confirm="router.delete(destroy.url(template.id))"
    />
</template>
