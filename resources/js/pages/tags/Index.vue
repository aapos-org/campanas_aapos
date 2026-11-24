<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { Check, Pencil, Tag, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import TagController from '@/actions/App/Http/Controllers/Contacts/TagController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/composables/useFormat';
import { destroy, index, update } from '@/routes/tags';

type TagRow = { id: number; name: string; contacts_count: number };

defineProps<{ tags: TagRow[] }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Etiquetas', href: index() }] },
});

const { number } = useFormat();
const editing = ref<number | null>(null);
const editName = ref('');
const editError = ref<string | undefined>();
const deleteTarget = ref<TagRow | null>(null);

const startEdit = (tag: TagRow) => {
    editing.value = tag.id;
    editName.value = tag.name;
    editError.value = undefined;
};

const saveEdit = (tag: TagRow) =>
    router.put(
        update.url(tag.id),
        { name: editName.value },
        {
            preserveScroll: true,
            onSuccess: () => (editing.value = null),
            onError: (e) => (editError.value = e.name),
        },
    );

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
    <Head title="Etiquetas" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Etiquetas"
            description="Marcas libres para agrupar contactos (p. ej. «Socio», «Congreso 2026»)."
        />

        <Form
            v-bind="TagController.store.form()"
            :options="{ preserveScroll: true }"
            reset-on-success
            class="flex max-w-xl flex-col gap-2 sm:flex-row sm:items-end"
            v-slot="{ errors, processing }"
        >
            <div class="grid flex-1 gap-1.5">
                <Label for="name">Nueva etiqueta</Label>
                <Input id="name" name="name" required />
                <InputError :message="errors.name" />
            </div>
            <Button type="submit" :disabled="processing">Crear</Button>
        </Form>

        <EmptyState
            v-if="tags.length === 0"
            :icon="Tag"
            title="Sin etiquetas"
            description="También se crean automáticamente al importar o al asignarlas en bloque."
        />

        <ul v-else class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
            <li
                v-for="tag in tags"
                :key="tag.id"
                class="flex items-center gap-2 glass-card p-3"
            >
                <template v-if="editing === tag.id">
                    <div class="flex-1">
                        <Input
                            v-model="editName"
                            :aria-label="`Nuevo nombre de ${tag.name}`"
                            v-focus
                            @keydown.enter.prevent="saveEdit(tag)"
                        />
                        <InputError :message="editError" />
                    </div>
                    <Button
                        size="icon"
                        aria-label="Guardar"
                        @click="saveEdit(tag)"
                    >
                        <Check />
                    </Button>
                </template>
                <template v-else>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">{{ tag.name }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ number(tag.contacts_count) }} contactos
                        </p>
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="`Renombrar ${tag.name}`"
                        @click="startEdit(tag)"
                    >
                        <Pencil />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="text-destructive"
                        :aria-label="`Eliminar ${tag.name}`"
                        @click="deleteTarget = tag"
                    >
                        <Trash2 />
                    </Button>
                </template>
            </li>
        </ul>
    </div>

    <ConfirmDialog
        :open="deleteTarget !== null"
        title="¿Eliminar la etiqueta?"
        :description="`Se quitará «${deleteTarget?.name}» de ${deleteTarget?.contacts_count ?? 0} contactos.`"
        confirm-label="Eliminar"
        destructive
        @update:open="(v) => !v && (deleteTarget = null)"
        @confirm="confirmDelete"
    />
</template>
