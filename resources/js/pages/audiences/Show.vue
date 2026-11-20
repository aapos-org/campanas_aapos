<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Download,
    Pencil,
    Tags,
    Trash2,
    Upload,
    UserPlus,
    Users,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, reactive, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ContactStatusBadge from '@/components/ContactStatusBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/composables/useFormat';
import {
    bulk,
    destroy,
    edit,
    exportMethod,
    index,
    show,
} from '@/routes/audiences';
import {
    create as createContact,
    show as showContact,
} from '@/routes/contacts';
import { create as createImport } from '@/routes/imports';
import type { Option, Paginated, TagRef } from '@/types';

type Row = {
    subscription_id: number;
    contact_id: number;
    email: string;
    name: string;
    status: string;
    status_label: string;
    subscribed_at: string | null;
    tags: TagRef[];
};

const props = defineProps<{
    audience: {
        id: number;
        name: string;
        description: string | null;
        double_opt_in: boolean;
    };
    counts: Record<string, number>;
    subscriptions: Paginated<Row>;
    filters: { search: string; status: string; tag: number | null };
    statuses: Option[];
    tags: TagRef[];
    canManage: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Audiencias', href: index() }] },
});

const { number, date } = useFormat();

const form = reactive({
    search: props.filters.search,
    status: props.filters.status,
    tag: props.filters.tag ? String(props.filters.tag) : '',
});

watchDebounced(
    form,
    () => {
        selected.value = [];
        router.get(
            show.url(props.audience.id),
            {
                search: form.search || undefined,
                status: form.status || undefined,
                tag: form.tag || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    },
    { debounce: 300 },
);

const total = computed(() =>
    Object.values(props.counts).reduce((sum, n) => sum + Number(n), 0),
);

const exportUrl = computed(() =>
    exportMethod.url(props.audience.id, {
        query: {
            status: form.status || undefined,
            tag: form.tag || undefined,
        },
    }),
);

// Bulk selection
const selected = ref<number[]>([]);
const allOnPageSelected = computed(
    () =>
        props.subscriptions.data.length > 0 &&
        props.subscriptions.data.every((r) =>
            selected.value.includes(r.contact_id),
        ),
);
const toggleAll = () => {
    selected.value = allOnPageSelected.value
        ? []
        : props.subscriptions.data.map((r) => r.contact_id);
};

const bulkTags = ref('');
const bulkProcessing = ref(false);
const runBulk = (action: 'add_tags' | 'remove_tags') => {
    const tags = bulkTags.value
        .split(',')
        .map((t) => t.trim())
        .filter(Boolean);

    if (!tags.length || !selected.value.length) {
        return;
    }

    router.post(
        bulk.url(props.audience.id),
        { action, contact_ids: selected.value, tags },
        {
            preserveScroll: true,
            onStart: () => (bulkProcessing.value = true),
            onFinish: () => (bulkProcessing.value = false),
            onSuccess: () => {
                selected.value = [];
                bulkTags.value = '';
            },
        },
    );
};

const confirmingDelete = ref(false);
const deleting = ref(false);
const deleteAudience = () =>
    router.delete(destroy.url(props.audience.id), {
        onStart: () => (deleting.value = true),
        onFinish: () => (deleting.value = false),
    });
</script>

<template>
    <Head :title="audience.name" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
        >
            <Heading
                :title="audience.name"
                :description="audience.description ?? undefined"
            />
            <div v-if="canManage" class="flex flex-wrap gap-2">
                <Button as-child size="sm">
                    <Link :href="createContact(audience.id)">
                        <UserPlus aria-hidden="true" />
                        Agregar contacto
                    </Link>
                </Button>
                <Button as-child size="sm" variant="secondary">
                    <Link :href="createImport(audience.id)">
                        <Upload aria-hidden="true" />
                        Importar
                    </Link>
                </Button>
                <Button as="a" :href="exportUrl" size="sm" variant="secondary">
                    <Download aria-hidden="true" />
                    Exportar CSV
                </Button>
                <Button as-child size="sm" variant="ghost">
                    <Link :href="edit(audience.id)">
                        <Pencil aria-hidden="true" />
                        Editar
                    </Link>
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    class="text-destructive"
                    @click="confirmingDelete = true"
                >
                    <Trash2 aria-hidden="true" />
                    Eliminar
                </Button>
            </div>
        </div>

        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <div class="rounded-xl border p-3">
                <dt class="text-xs text-muted-foreground">Total</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(total) }}
                </dd>
            </div>
            <div
                v-for="s in statuses"
                :key="s.value"
                class="rounded-xl border p-3"
            >
                <dt class="text-xs text-muted-foreground">{{ s.label }}</dt>
                <dd class="text-xl font-semibold tabular-nums">
                    {{ number(counts[s.value] ?? 0) }}
                </dd>
            </div>
        </dl>

        <form
            class="grid gap-3 sm:grid-cols-[1fr_12rem_12rem]"
            role="search"
            @submit.prevent
        >
            <div class="grid gap-1.5">
                <Label for="search">Buscar</Label>
                <Input
                    id="search"
                    v-model="form.search"
                    type="search"
                    placeholder="Correo o nombre"
                    autocomplete="off"
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="status">Estado</Label>
                <NativeSelect id="status" v-model="form.status">
                    <option value="">Todos</option>
                    <option
                        v-for="s in statuses"
                        :key="s.value"
                        :value="s.value"
                    >
                        {{ s.label }}
                    </option>
                </NativeSelect>
            </div>
            <div class="grid gap-1.5">
                <Label for="tag">Etiqueta</Label>
                <NativeSelect id="tag" v-model="form.tag">
                    <option value="">Todas</option>
                    <option v-for="t in tags" :key="t.id" :value="String(t.id)">
                        {{ t.name }}
                    </option>
                </NativeSelect>
            </div>
        </form>

        <EmptyState
            v-if="subscriptions.data.length === 0"
            :icon="Users"
            title="No hay contactos que coincidan"
            :description="
                total === 0
                    ? 'Agrega contactos uno por uno o impórtalos desde un archivo CSV o Excel.'
                    : 'Prueba con otros filtros.'
            "
        />

        <template v-else>
            <label
                v-if="canManage"
                class="flex items-center gap-2 text-sm lg:hidden"
            >
                <input
                    type="checkbox"
                    class="size-4"
                    :checked="allOnPageSelected"
                    @change="toggleAll"
                />
                Seleccionar todos en esta página
            </label>

            <!-- Mobile / tablet: cards -->
            <ul class="grid gap-3 lg:hidden">
                <li
                    v-for="row in subscriptions.data"
                    :key="row.subscription_id"
                    class="flex gap-3 rounded-xl border p-4"
                >
                    <input
                        v-if="canManage"
                        v-model="selected"
                        type="checkbox"
                        class="mt-1 size-4"
                        :value="row.contact_id"
                        :aria-label="`Seleccionar ${row.email}`"
                    />
                    <div class="min-w-0 flex-1">
                        <Link
                            :href="showContact(row.contact_id)"
                            class="block truncate font-medium hover:underline"
                            >{{ row.email }}</Link
                        >
                        <p
                            v-if="row.name"
                            class="truncate text-sm text-muted-foreground"
                        >
                            {{ row.name }}
                        </p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <ContactStatusBadge
                                :status="row.status"
                                :label="row.status_label"
                            />
                            <Badge
                                v-for="tag in row.tags"
                                :key="tag.id"
                                variant="secondary"
                                >{{ tag.name }}</Badge
                            >
                        </div>
                    </div>
                </li>
            </ul>

            <!-- Desktop: table -->
            <div class="hidden overflow-x-auto rounded-xl border lg:block">
                <table class="w-full text-sm">
                    <caption class="sr-only">
                        Contactos de la audiencia
                    </caption>
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th
                                v-if="canManage"
                                scope="col"
                                class="w-10 px-4 py-3"
                            >
                                <input
                                    type="checkbox"
                                    class="size-4"
                                    :checked="allOnPageSelected"
                                    aria-label="Seleccionar todos en esta página"
                                    @change="toggleAll"
                                />
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Contacto
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Estado
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Etiquetas
                            </th>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Suscrito desde
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in subscriptions.data"
                            :key="row.subscription_id"
                            class="border-t"
                        >
                            <td v-if="canManage" class="px-4 py-3">
                                <input
                                    v-model="selected"
                                    type="checkbox"
                                    class="size-4"
                                    :value="row.contact_id"
                                    :aria-label="`Seleccionar ${row.email}`"
                                />
                            </td>
                            <td class="px-4 py-3">
                                <Link
                                    :href="showContact(row.contact_id)"
                                    class="font-medium hover:underline"
                                    >{{ row.email }}</Link
                                >
                                <p
                                    v-if="row.name"
                                    class="text-muted-foreground"
                                >
                                    {{ row.name }}
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <ContactStatusBadge
                                    :status="row.status"
                                    :label="row.status_label"
                                />
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <Badge
                                        v-for="tag in row.tags"
                                        :key="tag.id"
                                        variant="secondary"
                                        >{{ tag.name }}</Badge
                                    >
                                </div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{ date(row.subscribed_at) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <div
            v-if="canManage && selected.length"
            class="sticky bottom-4 z-10 flex flex-col gap-3 rounded-xl border bg-background p-3 shadow-lg sm:flex-row sm:items-end"
            role="region"
            aria-label="Acciones en bloque"
        >
            <p class="text-sm font-medium sm:self-center">
                {{ selected.length }} seleccionados
            </p>
            <div class="grid flex-1 gap-1.5">
                <Label for="bulk-tags">Etiquetas (separadas por comas)</Label>
                <Input
                    id="bulk-tags"
                    v-model="bulkTags"
                    list="tag-names"
                    placeholder="Ej.: Socios, Congreso 2026"
                />
                <datalist id="tag-names">
                    <option v-for="t in tags" :key="t.id" :value="t.name" />
                </datalist>
            </div>
            <div class="flex gap-2">
                <Button
                    size="sm"
                    :disabled="bulkProcessing || !bulkTags.trim()"
                    @click="runBulk('add_tags')"
                >
                    <Tags aria-hidden="true" />
                    Agregar
                </Button>
                <Button
                    size="sm"
                    variant="secondary"
                    :disabled="bulkProcessing || !bulkTags.trim()"
                    @click="runBulk('remove_tags')"
                >
                    Quitar
                </Button>
            </div>
        </div>

        <Pagination :page="subscriptions" />
    </div>

    <ConfirmDialog
        v-model:open="confirmingDelete"
        title="¿Eliminar la audiencia?"
        description="La audiencia dejará de mostrarse. Los contactos y la lista de supresión se conservan."
        confirm-label="Eliminar"
        destructive
        :processing="deleting"
        @confirm="deleteAudience"
    />
</template>
