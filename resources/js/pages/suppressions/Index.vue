<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { ShieldBan, Trash2 } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { reactive, ref } from 'vue';
import SuppressionController from '@/actions/App/Http/Controllers/Contacts/SuppressionController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { destroy, index } from '@/routes/suppressions';
import type { Option, Paginated } from '@/types';

type Row = {
    id: number;
    email: string;
    scope: string;
    is_global: boolean;
    reason: string;
    reason_label: string;
    source: string | null;
    removable: boolean;
    created_at: string | null;
};

const props = defineProps<{
    suppressions: Paginated<Row>;
    filters: { search: string; reason: string };
    reasons: Option[];
    audiences: { id: number; name: string }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Lista de supresión', href: index() }] },
});

const { dateTime } = useFormat();
const filters = reactive({ ...props.filters });
const audienceId = ref('');
const removeTarget = ref<Row | null>(null);

watchDebounced(
    filters,
    () =>
        router.get(
            index.url(),
            {
                search: filters.search || undefined,
                reason: filters.reason || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        ),
    { debounce: 300 },
);

const confirmRemove = () => {
    if (removeTarget.value) {
        router.delete(destroy.url(removeTarget.value.id), {
            preserveScroll: true,
            onFinish: () => (removeTarget.value = null),
        });
    }
};
</script>

<template>
    <Head title="Lista de supresión" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Lista de supresión"
            description="Direcciones que nunca reciben campañas. Las bajas, rebotes duros y quejas se agregan solas y no se reactivan por importación."
        />

        <details class="glass-card p-4">
            <summary class="cursor-pointer font-medium">
                Agregar direcciones manualmente
            </summary>
            <Form
                v-bind="SuppressionController.store.form()"
                :transform="
                    (data) => ({ ...data, audience_id: audienceId || null })
                "
                :options="{ preserveScroll: true }"
                reset-on-success
                class="mt-4 grid gap-4"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-1.5">
                    <Label for="emails"
                        >Correos (separados por comas o líneas)</Label
                    >
                    <textarea
                        id="emails"
                        name="emails"
                        required
                        class="min-h-24 rounded-md border border-input bg-transparent px-3 py-2 text-sm dark:bg-input/30"
                    />
                    <InputError :message="errors.emails" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="audience_id">Alcance</Label>
                        <NativeSelect id="audience_id" v-model="audienceId">
                            <option value="">Todas las audiencias</option>
                            <option
                                v-for="a in audiences"
                                :key="a.id"
                                :value="String(a.id)"
                            >
                                Solo «{{ a.name }}»
                            </option>
                        </NativeSelect>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="detail">Motivo (opcional)</Label>
                        <Input id="detail" name="detail" />
                    </div>
                </div>
                <div>
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        Agregar a supresión
                    </Button>
                </div>
            </Form>
        </details>

        <form
            class="grid gap-3 sm:grid-cols-[1fr_14rem]"
            role="search"
            @submit.prevent
        >
            <div class="grid gap-1.5">
                <Label for="search">Buscar</Label>
                <Input
                    id="search"
                    v-model="filters.search"
                    type="search"
                    placeholder="Correo"
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="reason">Motivo</Label>
                <NativeSelect id="reason" v-model="filters.reason">
                    <option value="">Todos</option>
                    <option
                        v-for="r in reasons"
                        :key="r.value"
                        :value="r.value"
                    >
                        {{ r.label }}
                    </option>
                </NativeSelect>
            </div>
        </form>

        <EmptyState
            v-if="suppressions.data.length === 0"
            :icon="ShieldBan"
            title="No hay direcciones suprimidas"
        />

        <ul v-else class="grid gap-2">
            <li
                v-for="row in suppressions.data"
                :key="row.id"
                class="flex flex-col gap-2 glass-card p-3 sm:flex-row sm:items-center"
            >
                <div class="min-w-0 flex-1">
                    <p class="truncate font-medium">{{ row.email }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{ row.scope }} · {{ dateTime(row.created_at) }}
                        <template v-if="row.source">
                            · {{ row.source }}</template
                        >
                    </p>
                </div>
                <Badge
                    :variant="row.reason === 'manual' ? 'outline' : 'secondary'"
                    >{{ row.reason_label }}</Badge
                >
                <Button
                    v-if="row.removable"
                    variant="ghost"
                    size="sm"
                    class="text-destructive"
                    @click="removeTarget = row"
                >
                    <Trash2 aria-hidden="true" />
                    Quitar
                </Button>
            </li>
        </ul>

        <Pagination :page="suppressions" />
    </div>

    <ConfirmDialog
        :open="removeTarget !== null"
        title="¿Quitar de la lista de supresión?"
        :description="`${removeTarget?.email} podrá volver a suscribirse, pero no se reactivará automáticamente.`"
        confirm-label="Quitar"
        @update:open="(v) => !v && (removeTarget = null)"
        @confirm="confirmRemove"
    />
</template>
