<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Filter, Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref, watch } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/composables/useFormat';
import { create, destroy, edit, index } from '@/routes/segments';
import type { Paginated } from '@/types';

type Row = {
    id: number;
    name: string;
    audience: string | null;
    description: string;
    subscribed: number;
    from_mailchimp: boolean;
};

const props = defineProps<{
    segments: Paginated<Row>;
    audiences: { id: number; name: string }[];
    filters: { audience: number | null };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Segmentos', href: index() }] },
});

const { number } = useFormat();
const audience = ref<number | null>(props.filters.audience);
watch(audience, (value) =>
    router.get(
        index.url(),
        { audience: value || undefined },
        { preserveState: true, replace: true },
    ),
);

const deleting = ref<Row | null>(null);
</script>

<template>
    <Head title="Segmentos" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
        >
            <Heading
                title="Segmentos"
                description="Grupos de contactos definidos por condiciones (etiquetas, campos, actividad). Úsalos para dirigir campañas."
            />
            <Button as-child
                ><Link
                    :href="
                        create({ query: { audience: audience ?? undefined } })
                    "
                    ><Plus aria-hidden="true" />Nuevo segmento</Link
                ></Button
            >
        </div>

        <div class="grid max-w-xs gap-1.5">
            <Label for="audience">Audiencia</Label>
            <NativeSelect id="audience" v-model="audience">
                <option :value="null">Todas</option>
                <option v-for="a in audiences" :key="a.id" :value="a.id">
                    {{ a.name }}
                </option>
            </NativeSelect>
        </div>

        <EmptyState
            v-if="!segments.data.length"
            :icon="Filter"
            title="Aún no hay segmentos"
            description="Crea uno para enviar campañas solo a una parte de la audiencia."
        />

        <ul v-else class="grid gap-3">
            <li
                v-for="s in segments.data"
                :key="s.id"
                class="flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="min-w-0 space-y-1">
                    <p class="font-medium">
                        {{ s.name }}
                        <Badge
                            v-if="s.from_mailchimp"
                            variant="outline"
                            class="ml-1"
                            >Mailchimp</Badge
                        >
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ s.audience }} · {{ s.description }}
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <span class="text-sm tabular-nums"
                        ><strong>{{ number(s.subscribed) }}</strong>
                        suscritos</span
                    >
                    <Button variant="ghost" size="icon" as-child
                        ><Link
                            :href="edit(s.id)"
                            :aria-label="`Editar ${s.name}`"
                            ><Pencil aria-hidden="true" /></Link
                    ></Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="`Eliminar ${s.name}`"
                        @click="deleting = s"
                        ><Trash2 aria-hidden="true"
                    /></Button>
                </div>
            </li>
        </ul>

        <Pagination :page="segments" />
    </div>

    <ConfirmDialog
        :open="deleting !== null"
        title="¿Eliminar el segmento?"
        :description="`Se eliminará «${deleting?.name}». Los contactos no se modifican.`"
        confirm-label="Eliminar"
        destructive
        @update:open="(v: boolean) => !v && (deleting = null)"
        @confirm="
            deleting &&
            router.delete(destroy.url(deleting.id), {
                onFinish: () => (deleting = null),
            })
        "
    />
</template>
