<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ExternalLink, FileText, Plus } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useFormat } from '@/composables/useFormat';
import { create, edit, index } from '@/routes/forms';

type FormRow = {
    id: number;
    name: string;
    audience: string | null;
    is_active: boolean;
    double_opt_in: boolean;
    submissions_count: number;
    public_url: string;
};

defineProps<{ forms: FormRow[] }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Formularios', href: index() }] },
});

const { number } = useFormat();
</script>

<template>
    <Head title="Formularios" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                title="Formularios de suscripción"
                description="Página alojada o código para insertar en tu sitio web, con doble opt-in y protección contra bots."
            />
            <Button as-child class="w-full sm:w-auto">
                <Link :href="create()">
                    <Plus aria-hidden="true" />
                    Nuevo formulario
                </Link>
            </Button>
        </div>

        <EmptyState
            v-if="forms.length === 0"
            :icon="FileText"
            title="Aún no hay formularios"
            description="Crea uno por audiencia y publícalo en tu sitio o comparte su enlace."
        />

        <ul v-else class="grid gap-3">
            <li
                v-for="form in forms"
                :key="form.id"
                class="flex flex-col gap-3 glass-card p-4 sm:flex-row sm:items-center"
            >
                <div class="min-w-0 flex-1">
                    <Link
                        :href="edit(form.id)"
                        class="font-medium hover:underline"
                        >{{ form.name }}</Link
                    >
                    <p class="text-sm text-muted-foreground">
                        {{ form.audience }} ·
                        {{ number(form.submissions_count) }} envíos
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Badge
                        :variant="form.is_active ? 'secondary' : 'outline'"
                        >{{ form.is_active ? 'Activo' : 'Inactivo' }}</Badge
                    >
                    <Badge variant="outline">{{
                        form.double_opt_in ? 'Doble opt-in' : 'Alta simple'
                    }}</Badge>
                    <Button
                        as="a"
                        :href="form.public_url"
                        target="_blank"
                        rel="noopener"
                        variant="ghost"
                        size="sm"
                    >
                        <ExternalLink aria-hidden="true" />
                        Ver
                    </Button>
                </div>
            </li>
        </ul>
    </div>
</template>
