<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AudienceController from '@/actions/App/Http/Controllers/Contacts/AudienceController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, show } from '@/routes/audiences';

type EditableAudience = {
    id: number;
    name: string;
    description: string | null;
    default_from_name: string | null;
    default_from_email: string | null;
    permission_reminder: string | null;
    double_opt_in: boolean;
};

const props = defineProps<{ audience: EditableAudience | null }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Audiencias', href: index() }] },
});

const doubleOptIn = ref(props.audience?.double_opt_in ?? true);
const formDefinition = computed(() =>
    props.audience
        ? AudienceController.update.form(props.audience.id)
        : AudienceController.store.form(),
);
const textareaClass =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:bg-input/30';
</script>

<template>
    <Head :title="audience ? 'Editar audiencia' : 'Nueva audiencia'" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading :title="audience ? 'Editar audiencia' : 'Nueva audiencia'" />

        <Form
            v-bind="formDefinition"
            :transform="(data) => ({ ...data, double_opt_in: doubleOptIn })"
            class="max-w-2xl space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Nombre</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="audience?.name"
                    required
                    v-focus
                />
                <InputError :message="errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="description">Descripción (opcional)</Label>
                <textarea
                    id="description"
                    name="description"
                    :class="textareaClass"
                    :value="audience?.description ?? ''"
                />
                <InputError :message="errors.description" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="default_from_name">Nombre del remitente</Label>
                    <Input
                        id="default_from_name"
                        name="default_from_name"
                        :default-value="audience?.default_from_name ?? ''"
                    />
                    <InputError :message="errors.default_from_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="default_from_email">Correo del remitente</Label>
                    <Input
                        id="default_from_email"
                        name="default_from_email"
                        type="email"
                        :default-value="audience?.default_from_email ?? ''"
                    />
                    <InputError :message="errors.default_from_email" />
                </div>
            </div>
            <div class="grid gap-2">
                <Label for="permission_reminder">Recordatorio de permiso</Label>
                <textarea
                    id="permission_reminder"
                    name="permission_reminder"
                    :class="textareaClass"
                    :value="audience?.permission_reminder ?? ''"
                    placeholder="Recibes este correo porque te suscribiste a nuestro boletín."
                />
                <p class="text-sm text-muted-foreground">
                    Se muestra al pie de cada correo para recordar por qué la
                    persona lo recibe.
                </p>
                <InputError :message="errors.permission_reminder" />
            </div>
            <div class="flex items-start gap-3">
                <Checkbox
                    id="double_opt_in"
                    :model-value="doubleOptIn"
                    @update:model-value="(v) => (doubleOptIn = v === true)"
                />
                <div class="grid gap-1">
                    <Label for="double_opt_in"
                        >Doble opt-in en formularios</Label
                    >
                    <p class="text-sm text-muted-foreground">
                        Quien se suscribe por un formulario debe confirmar con
                        un enlace enviado a su correo (recomendado).
                    </p>
                </div>
            </div>

            <div class="flex flex-col-reverse gap-3 sm:flex-row">
                <Button variant="outline" as-child>
                    <Link :href="audience ? show(audience.id) : index()"
                        >Cancelar</Link
                    >
                </Button>
                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Guardar
                </Button>
            </div>
        </Form>
    </div>
</template>
